<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamClass;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\KonflikJadwal;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\SesiService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jadwal & sesi - wireframe plate 16, PRD §9.2, §9.3, §9.4, §9.5.
 *
 * ---------------------------------------------------------------------
 * Ujian lahir di laman ini, dan itu bukan penempatan yang serampangan
 * ---------------------------------------------------------------------
 * Plate 16 menamai tabelnya "Sesi | Tanggal | Jam | Ujian | Ruang", dan sesi
 * tidak bisa ada sebelum ujiannya ada. Kalau tombol "Tambah ujian" ditaruh di
 * laman lain - mis. di plate 15 bersama mata pelajaran - maka Admin menyusun
 * jadwal di satu laman dan membuat ujiannya di laman lain, berulang untuk
 * setiap ujian dalam event. Pada UAS dengan enam mapel itu dua belas
 * perpindahan laman untuk satu pekerjaan yang utuh.
 *
 * Karena itu toolbar plate 16 punya DUA tombol yang menambah sesuatu:
 * "Tambah ujian" (membuat ujiannya) dan "Tambah sesi" (menjadwalkannya).
 *
 * ---------------------------------------------------------------------
 * "Deteksi konflik" bukan tombol yang menghitung
 * ---------------------------------------------------------------------
 * Pemeriksaan konflik sudah berjalan setiap laman ini dimuat - panel
 * "Validasi jadwal" selalu terisi. Tombolnya ada karena plate 16
 * menampilkannya, dan karena Admin yang baru saja menutup satu tab lain
 * ingin MEMASTIKAN angka yang ia lihat masih yang terakhir. Yang
 * dilakukannya adalah memuat ulang dan menyatakan hasilnya dalam kalimat,
 * bukan menjalankan pemeriksaan yang berbeda dari yang sudah tampil.
 *
 * ---------------------------------------------------------------------
 * "Sebar peserta" ada di sini karena kapasitas hanya bisa lulus di sini
 * ---------------------------------------------------------------------
 * Readiness gate menolak "Siap" selama masih ada peserta yang belum
 * ditempatkan. Penempatan karena itu harus bisa dijangkau dari suatu laman,
 * dan laman yang benar adalah laman ini: plate 16 mencatat alasannya -
 * kapasitas ruang diperiksa di sini karena inilah tempat ruang dan peserta
 * pertama kali bertemu.
 *
 * Penyebarannya melaporkan hasil PER SESI, dan sesi yang kapasitasnya kurang
 * dilewati tanpa membatalkan sesi lain. Kegagalan yang membatalkan seluruh
 * penyebaran akan membuat satu sesi berkapasitas kurang menghalangi dua sesi
 * yang sebenarnya sudah bisa diisi.
 */
class ExamJadwalController
{
    /**
     * Jenis ujian - sama dengan daftar jenis kegiatan di Form event.
     *
     * Disalin, bukan dirujuk: yang satu menjawab "kegiatan ini apa" dan yang
     * satu "ujian ini apa", dan menyatukannya akan membuat menambah satu jenis
     * ujian mengubah pilihan pembuatan event.
     *
     * @var array<int, string>
     */
    public const JENIS = [
        'Ujian akhir semester',
        'Ujian tengah semester',
        'Ujian harian',
        'Ujian praktik',
        'Try out',
        'Penilaian remedial',
    ];

    public function index(SchoolEvent $event, KonflikJadwal $konflik, PenempatanService $penempatan): Response
    {
        Gate::authorize('view', $event);

        $ujian = $event->exams()
            ->with('subject:id,code,name')
            ->withCount('sessions')
            ->orderBy('code')
            ->get();

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->with([
                'exam:id,code,title,event_id,subject_id',
                'exam.subject:id,code,name',
                'rooms.eventRoom.room:id,code,name',
            ])
            ->orderBy('access_opens_at')
            ->orderBy('exam_id')
            ->orderBy('session_number')
            ->get();

        // Label ujian dihitung SEKALI untuk seluruh ujian, bukan sekali per
        // sesi: labelnya menjalankan satu query kelas sasaran, dan menghitungnya
        // di dalam map berarti tiga sesi satu ujian menjalankan query yang sama
        // tiga kali dengan jawaban yang identik.
        $label = $this->labelUjian($ujian);

        // Kelas sasaran per ujian, satu query untuk seluruh ujian - sama
        // alasannya dengan `$label` di atas. Panel "Ubah ujian" membuka dengan
        // kelas yang SEKARANG dicentang; form yang terbuka dengan centang
        // kosong pada ujian yang sudah punya kelas sasaran akan membuat Admin
        // yang menyunting durasinya sekaligus menghapus seluruh kelas
        // sasarannya - tanpa satu pun galat, karena `simpanKelas()` menerima
        // daftar kosong sebagai "seluruh peserta event".
        $kelasUjian = ExamClass::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->get()
            ->groupBy('exam_id');

        // Konflik diperiksa SEKALI, lalu hasilnya dipakai untuk dua keperluan:
        // peta per sesi (yang menandai barisnya) dan daftar temuan. Memanggil
        // periksa() dua kali berarti membaca seluruh jadwal event dua kali.
        $semuaTemuan = $konflik->periksa($event);

        $perSesi = [];

        foreach ($semuaTemuan as $temuan) {
            if ($temuan['sesi_id'] !== null) {
                $perSesi[$temuan['sesi_id']][] = [
                    'jenis' => $temuan['jenis'],
                    'pesan' => $temuan['pesan'],
                ];
            }
        }

        // Peserta ditempatkan per sesi, dalam satu query - bukan satu query per
        // baris tabel.
        $terisi = ExamParticipant::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->selectRaw('exam_session_id, count(*) as jumlah')
            ->groupBy('exam_session_id')
            ->pluck('jumlah', 'exam_session_id');

        return Inertia::render('Admin/Event/Jadwal', [
            'event' => $this->ringkas($event),

            'baris' => $sesi->map(fn (ExamSession $s) => [
                'id' => $s->id,
                'label' => $s->label(),
                'tanggal' => $s->access_opens_at?->format('d M'),
                'jam' => $s->access_opens_at?->format('H:i'),
                'selesai' => $s->hard_end_at?->format('H:i'),
                'ujian_id' => $s->exam_id,
                'ujian' => $label[$s->exam_id] ?? '—',
                'ruang' => $s->rooms->count(),
                'ruang_kode' => $s->rooms
                    ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code)
                    ->filter()
                    ->values()
                    ->all(),
                'peserta' => (int) ($terisi[$s->id] ?? 0),
                'status' => $s->status->value,
                'status_label' => $s->status->label(),

                // Nilai untuk panel "Ubah sesi". Tiga waktu dikirim dalam
                // bentuk `Y-m-d\TH:i` - bentuk yang diminta `datetime-local`,
                // sama seperti field Mulai/Selesai di form event. Dikirim
                // terpisah dari `tanggal`/`jam` yang sudah diformat untuk
                // DIBACA: yang satu untuk mata, yang satu untuk input, dan
                // menurunkan yang kedua dari yang pertama berarti mengurai
                // tampilan kembali menjadi nilai.
                'buka_iso' => $s->access_opens_at?->format('Y-m-d\TH:i'),
                'tutup_iso' => $s->access_closes_at?->format('Y-m-d\TH:i'),
                'akhir_iso' => $s->hard_end_at?->format('Y-m-d\TH:i'),
                // Id ruang-EVENT yang sedang dipakai sesi ini - yang perlu
                // dicentang ulang di panel ubah. Bukan id ruang-sesi: layar
                // mencentang ruang event, dan SesiService memetakannya kembali.
                'ruang_id' => $s->rooms
                    ->map(fn (ExamSessionRoom $r) => $r->event_room_id)
                    ->values()
                    ->all(),

                // Temuan menempel pada barisnya. Bentuknya daftar, bukan
                // boolean: satu sesi bisa bentrok di dua ruang sekaligus, dan
                // "bentrok: ya" tidak memberi tahu Admin mana yang harus
                // digeser.
                'bentrok' => $perSesi[$s->id] ?? [],
            ])->all(),

            'temuan' => collect($semuaTemuan)->map(fn (array $t) => [
                'jenis' => $t['jenis'],
                'pesan' => $t['pesan'],
                'sesi_id' => $t['sesi_id'],
            ])->all(),

            // Empat baris, bukan tiga seperti contoh plate: baris keempat -
            // benturan pengawas - benar-benar diperiksa sistem dan bisa
            // terjadi, dan menyembunyikannya berarti panel validasi melaporkan
            // "aman" untuk jadwal yang pengawasnya ditugaskan di dua ruang
            // sekaligus.
            'validasi' => $konflik->ringkas($event),

            'ujian' => $ujian->map(fn (Exam $u) => [
                'id' => $u->id,
                'kode' => $u->code,
                'judul' => $u->title,
                'mapel' => $u->subject?->name,
                'mapel_id' => $u->subject_id,
                // Ada-tidaknya paket menentukan apakah mapelnya masih boleh
                // diganti. Dikirim sebagai penanda, bukan sebagai id paketnya:
                // yang dibutuhkan layar hanyalah "sudah dibekukan atau belum",
                // dan id paket di peramban adalah rujukan yang tidak dipakai
                // siapa pun di sana.
                'ada_paket' => $u->question_package_id !== null,
                'jenis' => $u->exam_type,
                'durasi' => $u->duration_minutes,
                'instruksi' => $u->instructions,
                'percobaan' => $u->max_attempts,
                'acak_soal' => $u->shuffle_questions,
                'acak_opsi' => $u->shuffle_options,
                'boleh_kembali' => $u->allow_back_navigation,
                'boleh_terlambat' => $u->allow_late_entry,
                // Kelas sasaran yang sedang berlaku, untuk mencentang ulang
                // panel ubah. Bentuknya daftar id, bukan nama: yang dicentang
                // kotaknya adalah id kelasnya.
                'kelas_id' => ($kelasUjian->get($u->id) ?? collect())
                    ->pluck('class_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'sesi' => (int) $u->sessions_count,

                // Tidak ada `status` di sini sejak 2026-11-07. Kolomnya
                // dijatuhkan; yang menyatakan status adalah tiap baris sesi di
                // tabel `baris`, dan chip ujian yang selalu berbunyi "draft"
                // hanya membuat Admin mengira ada yang belum tertinjau.
            ])->all(),

            // Ruang yang sudah dialokasikan ke event - satu-satunya yang boleh
            // dipakai sesi. Ruang di luar daftar ini tidak ditawarkan sama
            // sekali, karena composite FK ke event_rooms akan menolaknya, dan
            // menawarkan pilihan yang pasti gagal bukan pilihan.
            'ruang' => $event->rooms()->with('room:id,code,name,capacity')->get()
                ->map(fn (EventRoom $r) => [
                    'id' => $r->id,
                    'kode' => $r->room?->code,
                    'nama' => $r->room?->name,
                    'kapasitas' => $r->kapasitasEfektif(),
                    'status' => $r->status,
                ])->all(),

            // Kelas & mapel untuk panel "Tambah ujian".
            'kelas' => SchoolClass::query()
                ->whereIn('id', $event->classes()->pluck('class_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SchoolClass $k) => ['id' => $k->id, 'nama' => $k->name])
                ->all(),

            'mapel' => $event->subjects()->with('subject:id,code,name')->get()
                ->map(fn ($s) => [
                    'id' => $s->subject?->id,
                    'kode' => $s->subject?->code,
                    'nama' => $s->subject?->name,
                ])
                ->filter(fn (array $m) => $m['id'] !== null)
                ->values()
                ->all(),

            'angka' => [
                'ujian' => $ujian->count(),
                'sesi' => $sesi->count(),
                'konflik' => count($semuaTemuan),
                'belum_ditempatkan' => $penempatan->belumDitempatkan($event),
            ],

            'jenis_ujian' => self::JENIS,
        ]);
    }

    /* -------------------------------------------------------- Ujian (§9.2) */

    /**
     * Tambah ujian - panel lengkap §9.2 di plate 16.
     */
    public function simpanUjian(
        Request $request,
        SchoolEvent $event,
        ExamRegistrar $registrar,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('create', Exam::class);

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:120'],
            'exam_type' => ['nullable', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'class_id' => ['nullable', 'array'],
            'class_id.*' => ['integer'],

            // Sakelar perilaku ujian. `validate()` hanya mengembalikan kunci
            // yang benar-benar dikirim, sehingga kotak centang yang tidak
            // dicentang memang tidak muncul - dan registrar memperlakukannya
            // sebagai false. Itu benar: tidak dicentang berarti tidak aktif.
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'allow_back_navigation' => ['nullable', 'boolean'],
            'allow_late_entry' => ['nullable', 'boolean'],

            // `publish_results` TIDAK ada di sini lagi. Fase 8 memindahkan
            // publikasi ke event (`school_events.results_published_at`), dan
            // aturannya satu aksi untuk seluruh event - plate 21. Kunci yang
            // tidak lagi dibaca siapa pun tidak divalidasi: menerimanya diam-
            // diam berarti formulir Admin mengira ia baru saja menyimpan
            // sesuatu.
        ]);

        // Mapel harus benar-benar milik event ini. Scope menyaring sekolahnya;
        // pemeriksaan ini menutup mapel tahun lain yang sekolahnya sama - dan
        // ujian tanpa mapel event adalah baris yang tidak muncul di laporan.
        $sah = $event->subjects()->where('subject_id', $data['subject_id'])->exists();

        if (! $sah) {
            return back()->with('galat', 'Mata pelajaran itu tidak ditetapkan di event ini.');
        }

        $ujian = $registrar->buat($event, $data, (int) $request->user()->id);

        $logger->log('Membuat ujian', $event, ['kode' => $ujian->code, 'judul' => $ujian->title]);

        return back()->with('sukses', "Ujian {$ujian->code} dibuat sebagai draft.");
    }

    public function ubahUjian(
        Request $request,
        SchoolEvent $event,
        Exam $ujian,
        ExamRegistrar $registrar,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $ujian);
        $this->pastikanMilikEvent($ujian->event_id, $event);

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:120'],
            'exam_type' => ['nullable', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'class_id' => ['nullable', 'array'],
            'class_id.*' => ['integer'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'allow_back_navigation' => ['nullable', 'boolean'],
            'allow_late_entry' => ['nullable', 'boolean'],
        ]);

        // Mapel hanya boleh berpindah selama belum ada paket yang dibekukan.
        // Snapshot soal berasal dari mapel yang lama, dan ujian yang
        // berlabel Fisika sementara isinya soal Matematika adalah kesalahan
        // yang tidak bisa dilihat dari layar mana pun - ia baru ketahuan
        // saat siswa membuka soal pertama di ruang ujian.
        if ((int) $data['subject_id'] !== (int) $ujian->subject_id
            && $ujian->question_package_id !== null) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mata pelajaran tidak bisa diganti setelah paket soal dipasang: soal yang sudah dibekukan berasal dari mapel yang lama.',
            ]);
        }

        $sah = $event->subjects()->where('subject_id', $data['subject_id'])->exists();

        if (! $sah) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mata pelajaran itu tidak ditetapkan di event ini.',
            ]);
        }

        $registrar->perbarui($ujian, $data);

        $logger->log('Memperbarui ujian', $event, ['kode' => $ujian->code]);

        return back()->with('sukses', 'Ujian tersimpan.');
    }

    /* -------------------------------------------------------- Sesi (§9.3) */

    public function simpanSesi(
        Request $request,
        SchoolEvent $event,
        Exam $ujian,
        SesiService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('create', Exam::class);
        $this->pastikanMilikEvent($ujian->event_id, $event);

        try {
            $sesi = $service->buat($ujian, $this->validasiSesi($request));
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Membuat sesi ujian', $event, [
            'ujian' => $ujian->code,
            'sesi' => $sesi->label(),
        ]);

        return back()->with('sukses', "Sesi {$sesi->label()} pada {$ujian->code} dibuat.");
    }

    public function ubahSesi(
        Request $request,
        SchoolEvent $event,
        Exam $ujian,
        ExamSession $sesi,
        SesiService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $sesi);
        $this->pastikanMilikEvent($ujian->event_id, $event);
        $this->pastikanSesiMilikUjian($sesi->exam_id, $ujian);

        try {
            $service->perbarui($sesi, $this->validasiSesi($request));
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Memperbarui sesi ujian', $event, ['sesi' => $sesi->label()]);

        return back()->with('sukses', "Sesi {$sesi->label()} tersimpan.");
    }

    /**
     * Buka sesi.
     *
     * Alasan penolakannya ditampilkan sebagai pesan, bukan 500: "ruang belum
     * punya pengawas" adalah keadaan yang sah pada jadwal yang baru disusun,
     * dan Admin perlu membaca ruang mana yang kurang - bukan halaman galat.
     */
    public function mulaiSesi(
        SchoolEvent $event,
        Exam $ujian,
        ExamSession $sesi,
        SesiService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $sesi);
        $this->pastikanMilikEvent($ujian->event_id, $event);
        $this->pastikanSesiMilikUjian($sesi->exam_id, $ujian);

        try {
            $service->mulai($sesi);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Membuka sesi ujian', $event, ['sesi' => $sesi->label()]);

        return back()->with('sukses', "Sesi {$sesi->label()} dibuka.");
    }

    /**
     * Siapkan sesi: jendelanya final, dan sesudah ini barulah ia bisa dibuka.
     *
     * Tanpa langkah ini, sesi yang lahir dari laman jadwal tidak pernah bisa
     * dibuka sama sekali - lihat docblock `SesiService::siapkan()`. Karena
     * itu tombolnya berdampingan dengan Ubah, bukan disembunyikan di panel
     * pengaturan: ia bagian dari alur menyusun jadwal, bukan tindakan khusus.
     */
    public function siapkanSesi(
        SchoolEvent $event,
        Exam $ujian,
        ExamSession $sesi,
        SesiService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $sesi);
        $this->pastikanMilikEvent($ujian->event_id, $event);
        $this->pastikanSesiMilikUjian($sesi->exam_id, $ujian);

        try {
            $service->siapkan($sesi);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menyiapkan sesi ujian', $event, ['sesi' => $sesi->label()]);

        return back()->with('sukses', "Sesi {$sesi->label()} disiapkan.");
    }

    public function tutupSesi(
        SchoolEvent $event,
        Exam $ujian,
        ExamSession $sesi,
        SesiService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $sesi);
        $this->pastikanMilikEvent($ujian->event_id, $event);
        $this->pastikanSesiMilikUjian($sesi->exam_id, $ujian);

        try {
            $service->tutup($sesi);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menutup sesi ujian', $event, ['sesi' => $sesi->label()]);

        return back()->with('sukses', "Sesi {$sesi->label()} ditutup.");
    }

    public function hapusSesi(
        SchoolEvent $event,
        Exam $ujian,
        ExamSession $sesi,
        SesiService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('delete', $sesi);
        $this->pastikanMilikEvent($ujian->event_id, $event);
        $this->pastikanSesiMilikUjian($sesi->exam_id, $ujian);

        $label = $sesi->label();

        try {
            $service->hapus($sesi);
        } catch (DomainException $e) {
            // Penghapusan bisa DITOLAK, dan penolakannya berkalimat: berita
            // acara yang sudah dikirim atau insiden yang tercatat di ruang
            // sesinya tidak boleh hilang bersama jadwalnya. Tanpa tangkapan
            // ini, yang sampai ke Admin adalah galat integritas 1451 dengan
            // nama constraint - lihat catatan `SesiService::hapus()`.
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menghapus sesi ujian', $event, ['sesi' => $label]);

        return back()->with('sukses', "Sesi {$label} dihapus.");
    }

    /**
     * Deteksi konflik - muat ulang laman beserta kalimat hasilnya.
     *
     * Pemeriksaannya sendiri sudah berjalan setiap laman dimuat; yang
     * ditambahkan tombol ini adalah pernyataannya dalam kalimat, supaya Admin
     * yang menekannya mendapat jawaban, bukan layar yang tampak tidak berubah.
     */
    public function deteksi(SchoolEvent $event, KonflikJadwal $konflik): RedirectResponse
    {
        Gate::authorize('view', $event);

        $temuan = $konflik->periksa($event);

        if ($temuan === []) {
            return back()->with('sukses', 'Tidak ada konflik jadwal yang ditemukan.');
        }

        return back()->with('galat', count($temuan).' konflik jadwal: '.$temuan[0]['pesan']);
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * @return array<string, mixed>
     */
    private function validasiSesi(Request $request): array
    {
        // Jendela sesi diperiksa di sini untuk pesan per-field di form, dan
        // diperiksa lagi di SesiService untuk menutup jalur tulis yang tidak
        // melewati form - seeder, penggandaan jadwal, impor. Duplikasi itu
        // disengaja: yang satu menerjemahkan, yang satu menegakkan.
        return $request->validate([
            'access_opens_at' => ['required', 'date'],
            'access_closes_at' => ['required', 'date'],
            'hard_end_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'event_room_id' => ['nullable', 'array'],
            'event_room_id.*' => ['integer'],
        ]);
    }

    /**
     * Baris anak harus benar-benar milik induk yang ada di URL.
     *
     * Binding implisit mencari id di SELURUH sekolah, bukan di dalam event atau
     * ujian yang disebut URL. Scope tenant tidak menutupnya karena sekolahnya
     * memang sama - sehingga tanpa pemeriksaan ini, ujian event B bisa diubah
     * lewat URL event A. 404, bukan 403: bagi event ini baris itu tidak ada.
     */
    private function pastikanMilikEvent(?int $eventId, SchoolEvent $event): void
    {
        abort_unless($eventId === $event->id, 404);
    }

    private function pastikanSesiMilikUjian(?int $examId, Exam $ujian): void
    {
        abort_unless($examId === $ujian->id, 404);
    }

    /**
     * Label ujian seperti yang dibaca plate 16: "Matematika · X-1, X-2".
     *
     * Dihitung untuk SELURUH ujian sekaligus, dalam satu query kelas sasaran -
     * bukan satu query per ujian. Kelas sasarannya diringkas dari daftar
     * kelasnya; ujian tanpa kelas sasaran berarti "seluruh peserta event", dan
     * itu ditulis apa adanya - bukan dibiarkan kosong sehingga terbaca seperti
     * ujian yang tidak punya peserta.
     *
     * @param  Collection<int, Exam>  $ujian
     * @return array<int, string>
     */
    private function labelUjian(Collection $ujian): array
    {
        if ($ujian->isEmpty()) {
            return [];
        }

        $kelas = ExamClass::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->with('kelas:id,name')
            ->get()
            ->groupBy('exam_id');

        $peta = [];

        foreach ($ujian as $u) {
            $nama = ($kelas->get($u->id) ?? collect())
                ->map(fn (ExamClass $k) => $k->kelas?->name)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $mapel = $u->subject?->name ?? $u->title;

            $peta[$u->id] = $nama === []
                ? $mapel.' · semua kelas'
                : $mapel.' · '.implode(', ', $nama);
        }

        return $peta;
    }

    /** @return array<string, mixed> */
    private function ringkas(SchoolEvent $event): array
    {
        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ];
    }
}
