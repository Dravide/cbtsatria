<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventReadiness;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use App\Support\TabelQuery;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event - wireframe plate 10 (daftar), 11 (buat/ubah), 12 (ringkasan & kesiapan).
 *
 * ---------------------------------------------------------------------
 * Tab daftar dihitung dari SELURUH event, bukan dari halaman
 * ---------------------------------------------------------------------
 * Anatomi wireframe butir 5: angka ringkasan ditulis di atas bilah filter,
 * supaya Admin melihat ukuran sebelum menyaring. Karena itu tab "Berjalan 1 /
 * Draft 1 / Selesai 2 / Arsip 3" dihitung dengan satu query agregat atas
 * seluruh event sekolah, bukan dengan menghitung baris yang kebetulan
 * terlihat di halaman pertama. Menghitung dari halaman akan menampilkan
 * "Draft 1" pada sekolah yang punya selusin draft, dan angka itu salah
 * tanpa terlihat salah.
 *
 * ---------------------------------------------------------------------
 * Konteks event disimpan di sesi, bukan hanya di URL
 * ---------------------------------------------------------------------
 * Anatomi butir 2 menyebut pemilih event sebagai "penangkal kesalahan paling
 * mahal di sistem ini: menetapkan peserta atau jadwal ke event yang salah."
 *
 * Karena itu membuka satu event MENULIS id-nya ke sesi, dan sidebar kelompok
 * event (yang muncul di Fase 5) membaca dari sana. URL tetap memuat id-nya
 * karena rutenya bersarang - tetapi URL saja tidak cukup: ia bisa dipalsukan
 * dengan mengetik, dan halaman yang terbuka dari tautan lama akan memindahkan
 * Admin ke event yang tidak sedang ia kerjakan tanpa ia sadari.
 */
class EventController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SchoolEvent::class);

        // Satu query agregat untuk keempat tab. Empat query count terpisah
        // untuk empat angka yang muncul bersamaan adalah empat perjalanan ke
        // basis data untuk satu baris KPI.
        $hitungan = SchoolEvent::query()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        $angka = fn (EventStatus ...$status) => array_sum(
            array_map(fn (EventStatus $s) => (int) ($hitungan[$s->value] ?? 0), $status),
        );

        $tab = (string) $request->query('tab', 'berjalan');

        $query = SchoolEvent::query()
            ->with(['academicYear:id,name', 'semester:id,name'])
            ->withCount([
                'participants as peserta_count' => fn ($q) => $q->where('status', EventParticipant::STATUS_TERDAFTAR),
                'exams as ujian_count',
            ]);

        // Tab bukan penyaring status yang sama: "Berjalan" mencakup seluruh
        // event yang belum tuntas, karena pertanyaan yang dijawab tab itu
        // adalah "apa yang masih perlu saya kerjakan", bukan "apa yang
        // kebetulan berstatus berjalan hari ini".
        match ($tab) {
            'draft' => $query->where('status', EventStatus::Draft),
            'selesai' => $query->where('status', EventStatus::Completed),
            'arsip' => $query->whereIn('status', [EventStatus::Archived, EventStatus::Cancelled]),
            default => $query->whereIn('status', EventStatus::belumSelesai()),
        };

        $halaman = TabelQuery::buat(
            $query,
            $request,
            cariDi: ['name', 'code'],
            bolehDiurut: ['name', 'code', 'start_at', 'status'],
            urutBawaan: 'start_at',
            arahBawaan: 'desc',
        );

        return Inertia::render('Admin/Event/Index', [
            'tab' => $tab,
            'tab_angka' => [
                'berjalan' => $angka(...EventStatus::belumSelesai()),
                'draft' => $angka(EventStatus::Draft),
                'selesai' => $angka(EventStatus::Completed),
                'arsip' => $angka(EventStatus::Archived, EventStatus::Cancelled),
            ],
            'baris' => $halaman->through(fn (SchoolEvent $e) => [
                'id' => $e->id,
                'kode' => $e->code,
                'nama' => $e->name,
                'periode' => $this->periode($e),
                'peserta' => (int) $e->peserta_count,
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'tahun' => $e->academicYear?->name,
                'semester' => $e->semester?->name,
                // Dua syarat tombol Hapus, dan keduanya dikirim sebagai data:
                // laman yang menghitungnya sendiri dari `status` akan terus
                // benar sampai syaratnya berubah, lalu menampilkan tombol yang
                // server tolak - dan tombol itu lebih buruk daripada tombol
                // yang tidak ada.
                'boleh_hapus' => $e->status === EventStatus::Draft && (int) $e->ujian_count === 0,
                'alasan_tolak_hapus' => match (true) {
                    $e->status !== EventStatus::Draft => 'Hanya event draft yang bisa dihapus.',
                    (int) $e->ujian_count > 0 => "Event ini sudah punya {$e->ujian_count} ujian.",
                    default => null,
                },
            ]),
            'filter' => ['cari' => $request->query('cari', '')],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', SchoolEvent::class);

        $sekolah = School::findOrFail($request->user()->school_id);
        $pengaturan = array_merge(PengaturanBawaan::gabung($sekolah->settings));

        return Inertia::render('Admin/Event/Form', [
            'event' => null,
            // Bawaan dari Pengaturan sekolah, ditampilkan sebagai nilai AWAL
            // tiga field di plate 11. Satu angka sekolah dipakai untuk
            // ketiganya - sesudah Admin menyimpan, ketiganya punya nilainya
            // sendiri dan tidak lagi menunjuk ke pengaturan.
            'bawaan' => [
                'ambang_peringatkan' => (int) $pengaturan['ambang_pelanggaran'],
                'ambang_tandai_pengawas' => (int) $pengaturan['ambang_pelanggaran'],
                'ambang_kunci_ujian' => (int) $pengaturan['ambang_pelanggaran'],
                'enforce_fullscreen' => (bool) $pengaturan['kunci_klik_kanan'],

                // Tiga penjaga layar, diteruskan sebagai centang awal di
                // plate 11 - alasan yang sama dengan ambang: yang disimpan
                // sekolah adalah bawaannya, dan event menyempitkannya sendiri.
                'detect_split_screen' => (bool) $pengaturan['deteksi_layar_terbagi'],
                'detect_float_window' => (bool) $pengaturan['deteksi_jendela_mengambang'],
                'show_watermark' => (bool) $pengaturan['tanda_air_ujian'],

                'timezone' => $sekolah->timezone,
            ],
            'tahun' => $this->daftarTahun(),
            'semester' => $this->daftarSemester(),
            'jenis' => $this->jenis(),
        ]);
    }

    public function store(Request $request, EventRegistrar $registrar, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', SchoolEvent::class);

        $data = $this->validasi($request);

        $sekolah = School::findOrFail($request->user()->school_id);
        $pengaturan = array_merge(PengaturanBawaan::gabung($sekolah->settings));

        $event = $registrar->buat($data, $request->user()->school_id, $request->user()->id, $pengaturan);

        $logger->log('Membuat event', $event, ['kode' => $event->code, 'nama' => $event->name]);

        // Diarahkan ke ringkasan, bukan kembali ke daftar: event yang baru
        // dibuat belum punya komponen apa pun, dan laman yang memberi tahu
        // apa yang kurang adalah laman berikutnya yang ia butuhkan.
        return redirect()
            ->route('admin.events.show', $event)
            ->with('sukses', "Event {$event->name} dibuat sebagai draft.");
    }

    public function show(Request $request, SchoolEvent $event, EventReadiness $kesiapan, EventStatusService $status): Response
    {
        Gate::authorize('view', $event);

        // Menulis konteks ke sesi - lihat catatan di atas.
        $request->session()->put('event_aktif', $event->id);

        $laporan = $kesiapan->periksa($event);

        return Inertia::render('Admin/Event/Show', [
            'event' => $this->ringkas($event),
            'kesiapan' => $laporan,
            'langkah' => $status->langkahTersedia($event),
            'angka' => [
                'peserta' => $event->participants()
                    ->where('status', EventParticipant::STATUS_TERDAFTAR)->count(),
                'kelas' => $event->classes()->count(),
                'mapel' => $event->subjects()->count(),
                'ruang' => $event->rooms()->count(),
                'pengawas' => $event->committee()
                    ->where('position', 'like', '%pengawas%')->count(),
                // Ujian dibuat di laman Jadwal & sesi (plate 16), karena sesi
                // tidak bisa ada tanpa ujiannya. Nol di sini berarti "belum
                // dibuat" - dan itulah yang membuat angkanya berarti.
                'ujian' => $event->exams()->count(),
            ],
        ]);
    }

    public function edit(SchoolEvent $event): Response
    {
        Gate::authorize('update', $event);

        $ambang = $event->ambang();

        return Inertia::render('Admin/Event/Form', [
            'event' => [
                ...$this->ringkas($event),
                'description' => $event->description,
                'semester_id' => $event->semester_id,
                'tahun_id' => $event->academic_year_id,
                'ambang_peringatkan' => $ambang->peringatkan,
                'ambang_tandai_pengawas' => $ambang->tandaiPengawas,
                'ambang_kunci_ujian' => $ambang->kunciUjian,
                'enforce_fullscreen' => $event->enforce_fullscreen,
                'detect_split_screen' => $event->detect_split_screen,
                'detect_float_window' => $event->detect_float_window,
                'show_watermark' => $event->show_watermark,
                'mulai' => $event->start_at?->format('Y-m-d\TH:i'),
                'selesai' => $event->end_at?->format('Y-m-d\TH:i'),
            ],
            'bawaan' => null,
            'tahun' => $this->daftarTahun(),
            'semester' => $this->daftarSemester(),
            'jenis' => $this->jenis(),
        ]);
    }

    public function update(Request $request, SchoolEvent $event, EventRegistrar $registrar, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $registrar->perbarui($event, $this->validasi($request, $event));

        $logger->log('Memperbarui event', $event, ['kode' => $event->code]);

        return back()->with('sukses', 'Event tersimpan.');
    }

    /**
     * Hapus event - hanya yang masih draft dan belum punya ujian.
     *
     * ---------------------------------------------------------------------
     * Dua syarat, dan keduanya menjaga satu hal yang sama: jejak
     * ---------------------------------------------------------------------
     * `EventPolicy::delete()` sudah membukanya untuk Admin sejak Fase 3,
     * dengan catatan bahwa UI-nya sengaja belum ada - "event yang salah
     * dibuat sudah punya jawabannya: Batal". Permintaan pemilik produk
     * 2026-11-13 membuka UI itu, dan yang harus dijaga karena itu bukan
     * izinnya melainkan BUKTINYA.
     *
     *   status draft     Event yang pernah disusun - apalagi yang sesinya
     *                    sudah berjalan - punya peserta, jadwal, dan
     *                    pengawas. Komponen itu tidak boleh hilang tanpa
     *                    satu kalimat pun; untuk keadaan itu jalannya Batal.
     *   tanpa ujian      `exams` adalah satu-satunya anak yang membawa
     *                    riwayat pengerjaan (attempt, jawaban, nilai,
     *                    berita acara, insiden). Tanpanya, yang ikut
     *                    terhapus hanyalah baris komponen event itu sendiri,
     *                    dan seluruh FK-nya CASCADE - tidak ada satu pun
     *                    RESTRICT yang menghalangi.
     *
     * Syarat kedua juga yang membuat penghapusan ini tidak perlu menyentuh
     * tabel modul lain sama sekali. Menghapus event ber-ujian berarti
     * menghapus attempt dan berita acara lintas modul dengan urutan yang
     * harus benar - dan itu pekerjaan `Batal`, bukan pekerjaan tombol di
     * daftar event.
     *
     * Diarahkan kembali ke daftar, bukan `back()`: barisnya sudah tidak ada,
     * dan halaman yang menyebut event yang baru saja dihapus adalah halaman
     * yang akan 404 pada penyegaran berikutnya.
     */
    public function destroy(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('delete', $event);

        if ($event->status !== EventStatus::Draft) {
            return back()->with(
                'galat',
                "Event berstatus {$event->status->label()} tidak bisa dihapus. ".
                'Gunakan Batal supaya jejak penyusunannya tetap tersimpan.',
            );
        }

        $jumlahUjian = $event->exams()->count();

        if ($jumlahUjian > 0) {
            return back()->with(
                'galat',
                "Event ini sudah punya {$jumlahUjian} ujian. Hapus ujiannya lebih dulu di laman ".
                'Jadwal & sesi, atau gunakan Batal bila eventnya memang tidak jadi dilaksanakan.',
            );
        }

        // Jejak ditulis SEBELUM barisnya hilang: `entity_id` yang menunjuk
        // baris yang tidak ada lagi tetap menjawab "event ini pernah dihapus,
        // oleh siapa, dan kapan" - dan itulah satu-satunya jawaban yang
        // tersisa sesudah penghapusan.
        $logger->log('Menghapus event', $event, [
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
        ]);

        // Dibaca SEBELUM barisnya hilang, bukan sesudah: seluruh atribut
        // model tetap ada di memori sesudah `delete()`, tetapi membaca apa
        // pun dari model yang sudah dihapus adalah kebiasaan yang cepat atau
        // lambat bertemu model yang memuat ulang dirinya sendiri.
        $nama = $event->name;
        $id = (int) $event->id;

        $event->delete();

        // Sesi yang menunjuk event ini akan 404 pada setiap permintaan
        // berikutnya. `HandleInertiaRequests` membersihkannya sendiri, tetapi
        // baru SETELAH satu permintaan penuh berjalan dengan konteks kosong -
        // dan Admin yang baru menghapus event melihat sidebar event hilang
        // satu ketukan sebelum ia kembali ke daftar. Membersihkannya di sini
        // membuat keadaannya benar sejak respons ini.
        if ((int) $request->session()->get('event_aktif') === $id) {
            $request->session()->forget('event_aktif');
        }

        return redirect()
            ->route('admin.events.index')
            ->with('sukses', "Event {$nama} dihapus.");
    }

    /**
     * Ubah status - satu-satunya jalur menuju EventStatusService.
     *
     * Galat DomainException diterjemahkan menjadi pesan di laman, bukan 500:
     * "belum siap" adalah keadaan yang sah, bukan kegagalan sistem, dan Admin
     * perlu membaca syarat mana yang kurang - bukan melihat halaman galat.
     */
    public function ubahStatus(
        Request $request,
        SchoolEvent $event,
        EventStatusService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('ubahStatus', $event);

        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $tujuan = EventStatus::tryFrom($data['status']);

        if ($tujuan === null) {
            return back()->with('galat', "Status '{$data['status']}' tidak dikenal.");
        }

        $asal = $event->status;

        try {
            $service->pindah($event, $tujuan);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Mengubah status event', $event, [
            'dari' => $asal->value,
            'ke' => $tujuan->value,
        ]);

        return back()->with('sukses', "Status event dipindahkan ke {$tujuan->label()}.");
    }

    /* ------------------------------------------------------------ Pembantu */

    /** @return array<string, mixed> */
    private function validasi(Request $request, ?SchoolEvent $event = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event_type' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:1000'],
            'academic_year_id' => [$event ? 'nullable' : 'required', 'integer'],
            'semester_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:40'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],

            // Tiga ambang - plate 11. Urutannya diperiksa di sini, sekali,
            // alih-alih di setiap tempat yang membandingkan: peringatkan <=
            // tandai <= kunci. Konfigurasi terbalik tidak menimbulkan galat
            // apa pun di kemudian hari - ia hanya membuat satu dari tiga
            // aturan tidak pernah tercapai.
            'ambang_peringatkan' => ['nullable', 'integer', 'min:1', 'max:50'],
            'ambang_tandai_pengawas' => ['nullable', 'integer', 'min:1', 'max:50'],
            'ambang_kunci_ujian' => ['nullable', 'integer', 'min:1', 'max:50'],

            'enforce_fullscreen' => ['nullable', 'boolean'],
            'detect_split_screen' => ['nullable', 'boolean'],
            'detect_float_window' => ['nullable', 'boolean'],
            'show_watermark' => ['nullable', 'boolean'],
        ]);

        $p = (int) ($data['ambang_peringatkan'] ?? 0);
        $t = (int) ($data['ambang_tandai_pengawas'] ?? 0);
        $k = (int) ($data['ambang_kunci_ujian'] ?? 0);

        if ($p && $t && $k && ! ($p <= $t && $t <= $k)) {
            throw ValidationException::withMessages([
                'ambang_kunci_ujian' => 'Urutan ambang harus menaik: peringatkan ≤ tandai pengawas ≤ kunci ujian.',
            ]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function ringkas(SchoolEvent $event): array
    {
        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'jenis' => $event->event_type,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'final' => $event->status->final(),
            'periode' => $this->periode($event),
            'tahun' => $event->academicYear?->name,
            'semester' => $event->semester?->name,
            'zona_waktu' => $event->school?->timezone,
            'dibuat_oleh' => $event->creator?->name,
        ];
    }

    /**
     * Periode sebagai teks, dan tanda pisah saat kosong.
     *
     * Event draft belum punya tanggal, dan menampilkan "01 Jan – 01 Jan"
     * untuknya berarti mengarang periode yang tidak pernah ditetapkan. Plate
     * 10 menampilkan "—" untuk baris seperti itu, dan itu memang jawabannya.
     */
    private function periode(SchoolEvent $event): ?string
    {
        if (! $event->start_at) {
            return null;
        }

        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        $mulai = $event->start_at->day.' '.$bulan[$event->start_at->month - 1];

        if (! $event->end_at) {
            return $mulai;
        }

        if ($event->start_at->month === $event->end_at->month) {
            return $mulai.'–'.$event->end_at->day;
        }

        return $mulai.'–'.$event->end_at->day.' '.$bulan[$event->end_at->month - 1];
    }

    /** @return array<int, array<string, mixed>> */
    private function daftarTahun(): array
    {
        return AcademicYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (AcademicYear $t) => [
                'id' => $t->id,
                'nama' => $t->name,
                'aktif' => $t->is_active,
            ])->all();
    }

    /**
     * Semester dikirim bersama tahun ajarannya, bukan sebagai daftar datar.
     *
     * Form plate 11 menampilkan keduanya berdampingan, dan semester yang
     * muncul harus semester milik tahun ajaran yang dipilih - bukan seluruh
     * semester sekolah. Mengirim daftar datar memaksa peramban menebak
     * hubungan itu, dan nebakannya akan salah begitu sekolah punya lebih dari
     * dua tahun ajaran.
     *
     * @return array<int, array<string, mixed>>
     */
    private function daftarSemester(): array
    {
        return Semester::query()
            ->orderByDesc('start_date')
            ->get(['id', 'academic_year_id', 'name'])
            ->map(fn (Semester $s) => [
                'id' => $s->id,
                'tahun_id' => $s->academic_year_id,
                'nama' => $s->name,
            ])->all();
    }

    /**
     * Jenis kegiatan - PRD §6.2 menyebutnya "jenis kegiatan" tanpa daftar.
     *
     * Daftar ini diusulkan, bukan dikutip: PRD tidak menetapkannya, sementara
     * plate 11 menampilkan satu field "Jenis" berisi "Ujian akhir semester".
     * Fieldnya tetap menerima teks bebas, sehingga sekolah yang punya istilah
     * sendiri tidak terhalang oleh daftar ini.
     *
     * @return array<int, string>
     */
    private function jenis(): array
    {
        return [
            'Ujian akhir semester',
            'Ujian tengah semester',
            'Ujian harian',
            'Ujian praktik',
            'Try out',
            'Penilaian remedial',
        ];
    }
}
