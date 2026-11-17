<?php

namespace App\Domain\Grading\Http\Controllers\Guru;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Support\RingkasJawaban;
use App\Domain\Event\Models\EventClass;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Services\EksporNilai;
use App\Domain\Grading\Support\UjianGuru;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nilai kelas - wireframe-peran.html plate 07.
 *
 * =====================================================================
 * Angkanya DIBACA dari `exam_results`, tidak dihitung di sini
 * =====================================================================
 * `PenghitungHasil` yang mengisi baris itu setiap kali attempt ditutup atau
 * koreksi disimpan. Laman ini hanya membaca, dengan alasan yang sama seperti
 * plate 08 dan plate 28: rata-rata yang dihitung ulang di setiap layar
 * adalah rata-rata yang bisa berbeda dari rata-rata di layar sebelahnya.
 *
 * =====================================================================
 * Uraian yang belum dikoreksi TIDAK dihitung nol
 * =====================================================================
 * Kalimat di plate 07: "Nilai uraian yang belum dikoreksi ditandai 'belum'
 * dan tidak ikut dihitung sebagai nol." Yang diterjemahkan ke data: kolom
 * "Uraian" bernilai null saat `pending_essays > 0`, kolom "Nilai" memakai
 * persentase yang SUDAH dihitung `PenghitungHasil` (pembilangnya tidak
 * terisi, penyebutnya penuh), dan barisnya membawa `final: false`.
 *
 * Menampilkan 0 akan membuat rata-rata kelas turun karena pekerjaan Guru
 * yang belum selesai - bukan karena siswa mengerjakan lebih buruk.
 *
 * =====================================================================
 * KKM dibaca dari pengaturan sekolah
 * =====================================================================
 * `exam_results.lulus()` menuntutnya sebagai parameter, dan yang benar-benar
 * menentukan KKM adalah pengaturan SEKOLAH - bukan angka yang diketik di
 * laman ini. Satu angka, satu tempat.
 */
class NilaiController
{
    /**
     * Daftar ujian yang punya hasil.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('koreksi');

        $user = $request->user();
        $daftar = UjianGuru::untuk($user->id)->orderByDesc('id')->get();

        $ringkas = $this->ringkasBanyak($daftar->pluck('id')->all());
        $kkm = $this->kkm();

        $ujian = $daftar
            ->map(fn (Exam $satu) => ($ringkas[$satu->id] ?? []) + [
                'ujian_id' => $satu->id,
                'mapel' => $satu->subject?->name,
                'judul' => $satu->title,
                'event' => $satu->event?->name,
            ])
            ->filter(fn (array $b) => ($b['hasil'] ?? 0) > 0)
            ->values();

        return Inertia::render('Guru/Nilai/Index', [
            'ujian' => $ujian->all(),
            'kkm' => $kkm,
        ]);
    }

    /**
     * Nilai satu ujian - plate 07.
     */
    public function ujian(Request $request, Exam $ujian): Response
    {
        Gate::authorize('koreksi');

        $guru = $request->user();
        $ujian = UjianGuru::satu($guru->id, $ujian->id);

        $kkm = $this->kkm();

        $hasil = ExamResult::query()
            ->whereIn('exam_attempt_id', ExamAttempt::query()
                ->whereHas('penempatan', fn ($q) => $q->where('exam_id', $ujian->id))
                ->select('id'))
            // Dua baris pendukung per halaman: peserta (nama/NIS/kelas) dan
            // status attempt-nya. Daftar nilai tanpa nama adalah daftar yang
            // tidak bisa dipakai.
            // `penempatan` menunjuk `exam_participants`, dan tabel itu tidak
            // punya `exam_participant_id` - namanya `event_participant_id`,
            // karena yang terdaftar di situ adalah peserta EVENT yang
            // ditempatkan ke sebuah ujian.
            ->with([
                'pengerjaan:id,exam_participant_id,status',
                'pengerjaan.penempatan:id,event_participant_id',
                'pengerjaan.penempatan.pesertaEvent:id,student_id,class_id',
                'pengerjaan.penempatan.pesertaEvent.student:id,name,username',
                'pengerjaan.penempatan.pesertaEvent.kelas:id,name',
            ]);

        // Penyaring kelas dilakukan di kueri, bukan di PHP: `per_page` dihitung
        // sesudah penyaringan, dan menyaring sesudah paginasi menghasilkan
        // halaman berisi tiga baris dari lima puluh.
        $kelas = $request->query('kelas');

        if ($kelas !== null && $kelas !== '') {
            $hasil->whereHas('pengerjaan.penempatan.pesertaEvent', fn ($q) => $q->where('class_id', $kelas));
        }

        $halaman = TabelQuery::buat(
            $hasil,
            $request,
            cariDi: [],
            bolehDiurut: ['final_percentage'],
            urutBawaan: 'id',
            arahBawaan: 'asc',
        );

        $ringkas = $this->ringkasBanyak([$ujian->id])[$ujian->id];

        return Inertia::render('Guru/Nilai/Ujian', [
            'ujian' => [
                'id' => $ujian->id,
                'judul' => $ujian->title,
                'kode' => $ujian->code,
                'mapel' => $ujian->subject?->name,
                'event' => $ujian->event?->name,
            ],
            'kkm' => $kkm,
            'ringkas' => $ringkas,
            'baris' => $halaman->through(function (ExamResult $r) use ($kkm) {
                $peserta = $r->pengerjaan?->penempatan?->pesertaEvent;

                return [
                    'hasil_id' => $r->id,
                    // Id penempatan peserta - dipakai baris ini untuk
                    // menautkan ke rincian per siswa. Nama dan NIS saja tidak
                    // cukup: rute rincian memakai id `exam_participants`, dan
                    // laman yang menyusunnya dari nama akan salah orang pada
                    // sekolah yang punya dua siswa bernama sama.
                    'peserta_id' => $peserta?->id,
                    'nis' => $peserta?->student?->username,
                    'nama' => $peserta?->student?->name,
                    'kelas' => $peserta?->kelas?->name,
                    'objektif' => (float) $r->automatic_score,
                    // Null saat uraian belum selesai dikoreksi - lihat catatan
                    // kepala kelas. Nol di sini berarti "dinilai, tidak dapat
                    // poin", dan itu kalimat yang berbeda.
                    'uraian' => $r->final() ? (float) $r->essay_score : null,
                    'nilai' => (float) $r->final_percentage,
                    'final' => $r->final(),
                    'sisa_uraian' => (int) $r->pending_essays,
                    'status' => $r->grading_status->value,
                    'status_label' => $r->grading_status->label(),
                    'status_nada' => $r->grading_status->nada(),
                    // `lulus` null saat belum final, dan laman yang
                    // menampilkannya sebagai "remedial" akan menuduh siswa
                    // atas pekerjaan Guru yang belum selesai.
                    'lulus' => $r->final() ? $r->lulus($kkm) : null,
                ];
            }),
            'filter' => ['kelas' => $kelas ?? ''],
            // Kelas yang memang peserta event ini - bukan seluruh kelas
            // sekolah. Penyaring yang menawarkan kelas yang tidak ikut event
            // akan selalu menghasilkan daftar kosong, dan Guru yang memilihnya
            // akan mengira nilainya hilang.
            'kelas' => SchoolClass::query()
                ->whereIn('id', EventClass::query()
                    ->where('event_id', $ujian->event_id)
                    ->select('class_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SchoolClass $k) => ['id' => (string) $k->id, 'label' => $k->name])
                ->all(),
        ]);
    }

    /**
     * Unduh nilai satu ujian sebagai XLSX - per kelas, atau seluruhnya.
     *
     * ---------------------------------------------------------------------
     * Berkasnya hanya lahir bila SELURUH barisnya sudah final
     * ---------------------------------------------------------------------
     * Kalimat yang sama dengan plate 07, tetapi dengan akibat yang lebih
     * keras: di layar, uraian yang belum dikoreksi tampil "belum" dan kolom
     * Nilai tetap terbaca sebagai angka sementara. Di berkas, kolom itu
     * menjadi SEL yang dijumlahkan Excel - dan rata-rata yang dihitung
     * Guru dari berkas itu akan berbeda dari rata-rata yang ia lihat di
     * layar, tanpa satu pun tanda bahwa penyebabnya koreksi yang belum
     * selesai.
     *
     * Karena itu permintaannya DITOLAK dengan alasan, bukan dilayani dengan
     * sel kosong. Berkas yang menyembunyikan pekerjaan yang belum selesai
     * adalah berkas yang akan beredar sebagai nilai akhir.
     *
     * ---------------------------------------------------------------------
     * Penyaring kelas memakai aturan yang SAMA dengan laman
     * ---------------------------------------------------------------------
     * `kelas` di sini adalah `class_id` peserta, bukan nama kelas: nama
     * kelas bisa berubah dan tidak unik antar tahun ajaran, sementara id
     * menunjuk baris yang sama dengan yang dipakai penyaring layar.
     */
    public function unduh(Request $request, Exam $ujian, EksporNilai $ekspor): HttpResponse|RedirectResponse
    {
        Gate::authorize('koreksi');

        $guru = $request->user();
        $ujian = UjianGuru::satu($guru->id, $ujian->id);

        $kelas = $request->query('kelas');
        $kelas = is_string($kelas) && $kelas !== '' ? $kelas : null;

        $hasil = ExamResult::query()
            ->whereIn('exam_attempt_id', ExamAttempt::query()
                ->whereHas('penempatan', fn ($q) => $q->where('exam_id', $ujian->id))
                ->select('id'))
            ->with([
                'pengerjaan:id,exam_participant_id',
                'pengerjaan.penempatan:id,event_participant_id',
                'pengerjaan.penempatan.pesertaEvent:id,student_id,class_id',
                'pengerjaan.penempatan.pesertaEvent.student:id,name,username',
                'pengerjaan.penempatan.pesertaEvent.kelas:id,name',
            ])
            ->when($kelas !== null, fn ($q) => $q->whereHas(
                'pengerjaan.penempatan.pesertaEvent',
                fn ($s) => $s->where('class_id', $kelas),
            ))
            ->get();

        // Dua penolakan, dan keduanya menyebut apa yang harus dilakukan
        // berikutnya - Guru yang menerima "tidak bisa diunduh" tanpa sebab
        // akan menekan tombolnya lagi.
        if ($hasil->isEmpty()) {
            return back()->with('galat', $kelas === null
                ? 'Belum ada nilai pada ujian ini, jadi belum ada yang bisa diunduh.'
                : 'Kelas ini belum punya nilai pada ujian ini.');
        }

        $belumFinal = $hasil->filter(fn (ExamResult $r) => ! $r->final())->count();

        if ($belumFinal > 0) {
            return back()->with('galat', "Masih ada {$belumFinal} siswa yang uraiannya belum dikoreksi. "
                .'Unduhan baru tersedia setelah semuanya dikoreksi - berkas dengan sel kosong akan terbaca sebagai nilai akhir.');
        }

        $baris = $hasil->map(fn (ExamResult $r) => [
            'nis' => $r->pengerjaan?->penempatan?->pesertaEvent?->student?->username,
            'nama' => $r->pengerjaan?->penempatan?->pesertaEvent?->student?->name,
            'kelas' => $r->pengerjaan?->penempatan?->pesertaEvent?->kelas?->name,
            'objektif' => (float) $r->automatic_score,
            'uraian' => (float) $r->essay_score,
            'nilai' => (float) $r->final_percentage,
            'status_label' => $r->grading_status->label(),
        ])->sortBy(fn (array $b) => (string) $b['kelas'].'|'.(string) $b['nama'])->values();

        $namaKelas = $kelas === null ? null : $hasil->first()?->pengerjaan?->penempatan?->pesertaEvent?->kelas?->name;

        // Dikirim langsung, bukan lewat disk: berkasnya satu ujian, selesai
        // dalam milidetik, dan tautan yang mati karena berkasnya dipangkas
        // adalah kegagalan yang tidak perlu diciptakan - lihat catatan yang
        // sama di EventCommitteeController::ekspor().
        return response($ekspor->buat($ujian, $baris), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$ekspor->namaBerkas($ujian, $namaKelas).'"',
        ]);
    }

    /**
     * Rincian satu siswa: soal, jawabannya, dan linimasa pengerjaannya.
     *
     * ---------------------------------------------------------------------
     * Apa yang BENAR-BENAR tersimpan, dan apa yang tidak
     * ---------------------------------------------------------------------
     * Tidak ada log per klik. Yang tersimpan hanya tiga hal yang bertanggal:
     *
     *   `exam_attempts.started_at`      kapan anak menekan Mulai;
     *   `student_answers.saved_at`      penyimpanan TERAKHIR tiap butir;
     *   `exam_violations.occurred_at`   kejadian yang dicatat mesin.
     *
     * Karena itu linimasa di sini menyebut "jawaban nomor 7 tersimpan pukul
     * 07:34", bukan "nomor 7 diklik pukul 07:33:41". Setiap penyimpanan
     * menimpa yang sebelumnya - itu memang disengaja, `version` menaik dan
     * `saved_at` diganti. Menampilkan lebih dari ini berarti mengarang.
     *
     * `started_at` dan `saved_at` cukup untuk menjawab pertanyaan Guru yang
     * sungguhan: "apakah anak ini mengebut di sepuluh menit terakhir" dan
     * "butir mana yang dikerjakan paling akhir". Selisih antar-`saved_at`
     * adalah waktu yang ia habiskan di butir berikutnya.
     *
     * ---------------------------------------------------------------------
     * Guru melihat kunci dan rubrik; siswa tidak
     * ---------------------------------------------------------------------
     * Laman ini konsol GURU, yang menilai jawaban - jadi kunci dan rubrik
     * ikut dikirim. Aturan yang sama sudah berlaku di `KoreksiController`.
     * Yang TIDAK dikirim tetap satu: rubrik tidak pernah dikirim ke siswa,
     * dan itu ditegakkan di `RiwayatSiswaController`, bukan di sini.
     */
    public function siswa(Request $request, Exam $ujian, ExamParticipant $peserta, RingkasJawaban $ringkas): Response
    {
        Gate::authorize('koreksi');

        $guru = $request->user();
        $ujian = UjianGuru::satu($guru->id, $ujian->id);

        // Peserta harus benar-benar peserta ujian INI. Binding implisit
        // menjamin barisnya ada di sekolah ini, bukan di ujian ini - dan
        // tanpa pemeriksaan ini Guru bisa membaca jawaban siswa dari ujian
        // lain dengan menebak id-nya. 404, bukan 403: yang 403 membuktikan
        // barisnya ada di tempat lain.
        abort_unless((int) $peserta->exam_id === (int) $ujian->id, 404);

        $peserta->loadMissing([
            'student:id,name,username',
            'student.studentProfile:id,user_id,nis',
            'pesertaEvent:id,student_id,class_id',
            'pesertaEvent.kelas:id,name',
        ]);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        $kkm = $this->kkm();
        $hasil = $attempt === null ? null : ExamResult::query()
            ->where('exam_attempt_id', $attempt->id)
            ->first();

        $butir = $attempt === null ? [] : $this->butirSiswa($attempt, $ringkas);

        return Inertia::render('Guru/Nilai/Siswa', [
            'ujian' => [
                'id' => $ujian->id,
                'judul' => $ujian->title,
                'kode' => $ujian->code,
                'mapel' => $ujian->subject?->name,
            ],
            'siswa' => [
                'peserta_id' => $peserta->id,
                'nama' => $peserta->student?->name,
                'nis' => $peserta->student?->studentProfile?->nis
                    ?? $peserta->student?->username,
                'kelas' => $peserta->pesertaEvent?->kelas?->name,
            ],
            'pengerjaan' => $attempt === null ? null : [
                'id' => $attempt->id,
                'nomor_attempt' => $attempt->attempt_number,
                'status' => $attempt->status,
                'status_label' => $attempt->labelStatus(),
                // Diisi bila attempt-nya diulang - Guru perlu tahu bahwa
                // yang dibacanya adalah percobaan KEDUA, bukan satu-satunya.
                'mulai' => $attempt->started_at?->toIso8601String(),
                'mulai_label' => $attempt->started_at?->format('d M Y · H:i'),
                'batas' => $attempt->deadline_at?->format('H:i'),
                'dikumpulkan' => $attempt->submitted_at?->toIso8601String(),
                'dikumpulkan_label' => $attempt->submitted_at?->format('d M Y · H:i'),
                'terakhir_terlihat' => $attempt->last_seen_at?->format('d M · H:i'),
                'pelanggaran' => (int) $attempt->violation_count,
                'terberat' => (int) $attempt->violation_worst_severity,
                'terkunci' => $attempt->dikunci(),
                'kunci_alasan' => $attempt->lock_reason,
                // Detik yang dipakai mengerjakan - jam dinding, bukan waktu
                // aktif. Mesin ujian tidak menyimpan akumulasi waktu aktif,
                // dan angka yang dikarang di sini akan dibandingkan dengan
                // jam di kepala anak.
                'durasi_detik' => $attempt->started_at !== null && $attempt->submitted_at !== null
                    ? max(0, (int) $attempt->started_at->diffInSeconds($attempt->submitted_at, false))
                    : null,
            ],
            'hasil' => $hasil === null ? null : [
                'objektif' => (float) $hasil->automatic_score,
                'uraian' => $hasil->final() ? (float) $hasil->essay_score : null,
                'nilai' => (float) $hasil->final_percentage,
                'final' => $hasil->final(),
                'sisa_uraian' => (int) $hasil->pending_essays,
                'status' => $hasil->grading_status->value,
                'status_label' => $hasil->grading_status->label(),
                'lulus' => $hasil->final() ? (float) $hasil->final_percentage >= $kkm : null,
            ],
            'kkm' => $kkm,
            'butir' => $butir,
            // Linimasa DIRAKIT dari `butir` dan attempt - bukan kueri kedua
            // atas tabel yang sama. Dua pembacaan atas data yang sama adalah
            // dua linimasa yang bisa berbeda diam-diam.
            'linimasa' => $this->linimasa($attempt, $butir),
            'jalur_ujian' => "/guru/nilai/{$ujian->id}",
        ]);
    }

    /**
     * Butir satu attempt dengan soal, jawaban, kunci, dan skornya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function butirSiswa(ExamAttempt $attempt, RingkasJawaban $ringkas): array
    {
        return AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with(['soal', 'jawaban'])
            ->orderBy('display_order')
            ->get()
            ->map(function (AttemptQuestion $b) use ($ringkas) {
                $soal = $b->soal;
                $jawaban = $b->jawaban;

                if ($soal === null) {
                    // Snapshot yang hilang tetap dikirim, bukan dibuang -
                    // nomor soal adalah hal yang disebut pengawas, dan
                    // membuangnya menggeser seluruh nomor sesudahnya.
                    return [
                        'id' => $b->id,
                        'nomor' => $b->display_order,
                        'jenis_label' => 'Soal tidak tersedia',
                        'judul' => null,
                        'soal' => null,
                        'opsi' => [],
                        'jawaban' => null,
                        'kunci' => null,
                        'benar' => null,
                        'skor' => null,
                        'bobot' => 0.0,
                        'keadaan' => 'belum',
                        'tersimpan' => null,
                        'versi' => null,
                        'ditandai' => false,
                    ];
                }

                $jenis = $soal->jenis;

                return [
                    'id' => $b->id,
                    'nomor' => $b->display_order,
                    'jenis' => $jenis->value,
                    'jenis_label' => $jenis->label(),
                    'judul' => $soal->judul,
                    'soal' => $this->teksSoal($soal->soal_json),
                    'opsi' => $this->opsiSoal($soal->opsi_json),

                    'jawaban' => $jawaban === null
                        ? null
                        : $ringkas->jawaban($jenis, $jawaban->answer_json, $soal->butir_json),

                    // Guru melihat kunci. Rubrik tidak: ia pedoman yang
                    // dibaca di laman koreksi, dan menampilkannya di sini
                    // berarti dua tempat yang harus sepakat tentang cara
                    // membaca rubrik.
                    'kunci' => $ringkas->kunci($jenis, $soal->kunci_json, $soal->butir_json),

                    'benar' => $jawaban?->is_correct,
                    'skor' => $jawaban?->score_awarded === null
                        ? null
                        : (float) $jawaban->score_awarded,
                    'bobot' => $soal->bobotBerlaku(),
                    'keadaan' => $this->keadaanButir($jawaban?->is_correct, $jawaban?->score_awarded),

                    // Waktu penyimpanan TERAKHIR butir ini, apa adanya. Ia
                    // diganti setiap penyimpanan - lihat catatan kepala
                    // method `siswa()`.
                    'tersimpan' => $jawaban?->saved_at?->toIso8601String(),
                    'tersimpan_label' => $jawaban?->saved_at?->format('H:i:s'),
                    'versi' => $jawaban?->version,
                    'ditandai' => (bool) ($jawaban?->ditandai ?? false),
                ];
            })
            ->all();
    }

    /**
     * Linimasa pengerjaan - dari data yang BERTANGGAL, tidak dikarang.
     *
     * Tiga jenis kejadian, berurutan waktu:
     *
     *   mulai      `attempts.started_at`
     *   jawab      `student_answers.saved_at`, satu per butir
     *   kumpul     `attempts.submitted_at`
     *
     * Diurutkan menaik supaya bisa dibaca sebagai cerita. Butir yang
     * tersimpan pada jam yang sama (autosave dua butir beruntun) tetap
     * ditampilkan satu per satu - menyatukannya akan menghapus informasi
     * "ia mengerjakan dua soal dalam satu detik", dan itu justru yang
     * dicari Guru.
     *
     * @param  array<int, array<string, mixed>>  $butir
     * @return array<int, array<string, mixed>>
     */
    private function linimasa(?ExamAttempt $attempt, array $butir): array
    {
        if ($attempt === null) {
            return [];
        }

        $kejadian = [];

        if ($attempt->started_at !== null) {
            $kejadian[] = [
                'jenis' => 'mulai',
                'label' => 'Menekan Mulai',
                'pada' => $attempt->started_at->toIso8601String(),
                'jam' => $attempt->started_at->format('H:i:s'),
                'detik' => 0,
                'nomor' => null,
            ];
        }

        $awal = $attempt->started_at;

        foreach ($butir as $b) {
            if ($b['tersimpan'] === null) {
                continue;
            }

            $pada = Carbon::parse($b['tersimpan']);

            $kejadian[] = [
                'jenis' => 'jawab',
                'label' => 'Jawaban nomor '.$b['nomor'].' tersimpan',
                'pada' => $b['tersimpan'],
                'jam' => $b['tersimpan_label'],
                // Detik sejak Mulai - dipakai laman untuk kolom "menit ke-",
                // dan dihitung di server supaya jawaban dan rumusnya satu.
                'detik' => $awal === null ? null : max(0, (int) $awal->diffInSeconds($pada, false)),
                'nomor' => $b['nomor'],
                'keadaan' => $b['keadaan'],
            ];
        }

        if ($attempt->submitted_at !== null) {
            $kejadian[] = [
                'jenis' => 'kumpul',
                'label' => 'Mengumpulkan',
                'pada' => $attempt->submitted_at->toIso8601String(),
                'jam' => $attempt->submitted_at->format('H:i:s'),
                'detik' => $awal === null
                    ? null
                    : max(0, (int) $awal->diffInSeconds($attempt->submitted_at, false)),
                'nomor' => null,
            ];
        }

        usort($kejadian, fn (array $a, array $b) => strcmp((string) $a['pada'], (string) $b['pada']));

        return $kejadian;
    }

    /** Empat keadaan butir - sama dengan riwayat sisi siswa. */
    private function keadaanButir(?bool $benar, mixed $skor): string
    {
        if ($benar === true) {
            return 'benar';
        }

        if ($benar === false) {
            return 'salah';
        }

        return $skor === null ? 'belum' : 'dinilai';
    }

    /** @param  array<string, mixed>|null  $isi */
    private function teksSoal(?array $isi): ?string
    {
        $teks = $isi['teks'] ?? null;

        return is_string($teks) && trim($teks) !== '' ? trim($teks) : null;
    }

    /**
     * Opsi snapshot sebagai peta kunci-ke-teks.
     *
     * @param  array<int, array<string, mixed>>|null  $opsi
     * @return array<string, string>
     */
    private function opsiSoal(?array $opsi): array
    {
        if (! is_array($opsi)) {
            return [];
        }

        $peta = [];

        foreach ($opsi as $satu) {
            if (! is_array($satu) || ! isset($satu['kunci'])) {
                continue;
            }

            $isi = $satu['teks'] ?? null;

            if (is_array($isi)) {
                $isi = $isi['teks'] ?? null;
            }

            $peta[(string) $satu['kunci']] = is_string($isi) ? trim($isi) : '';
        }

        return $peta;
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Ringkasan hasil untuk sekumpulan ujian - empat KPI plate 07.
     *
     * Rata-rata, tertinggi, dan jumlah remedial dihitung dari baris yang
     * SUDAH FINAL saja. Mamasukkan hasil yang masih menunggu koreksi ke
     * rata-rata berarti rata-rata kelas berubah setiap kali satu uraian
     * dikoreksi - dan Guru yang membacanya akan mengira kelasnya memburuk.
     *
     * @param  array<int, int>  $ujianIds
     * @return array<int, array{siapa: int, hasil: int, rata_rata: float|null, tertinggi: float|null, remedial: int, belum_final: int}>
     */
    private function ringkasBanyak(array $ujianIds): array
    {
        if ($ujianIds === []) {
            return [];
        }

        $kkm = $this->kkm();

        $seluruh = ExamResult::query()
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_results.exam_attempt_id')
            ->join('exam_participants', 'exam_participants.id', '=', 'exam_attempts.exam_participant_id')
            ->join('exams', 'exams.id', '=', 'exam_participants.exam_id')
            ->whereIn('exams.id', $ujianIds)
            ->selectRaw('exams.id as ujian_id, exam_results.final_percentage, exam_results.pending_essays')
            ->get();

        $hasil = [];

        foreach ($ujianIds as $id) {
            $baris = $seluruh->where('ujian_id', $id);
            $final = $baris->where('pending_essays', 0);

            $hasil[$id] = [
                'hasil' => $baris->count(),
                'rata_rata' => $final->isNotEmpty() ? round((float) $final->avg('final_percentage'), 2) : null,
                'tertinggi' => $final->isNotEmpty() ? round((float) $final->max('final_percentage'), 2) : null,
                'remedial' => $final->filter(fn ($r) => (float) $r->final_percentage < $kkm)->count(),
                'belum_final' => $baris->count() - $final->count(),
            ];
        }

        return $hasil;
    }

    /** KKM sekolah, dengan batas akal yang sama seperti di pengaturannya. */
    private function kkm(): int
    {
        $nilai = (int) (auth()->user()?->school?->settings['kkm'] ?? PengaturanBawaan::NILAI['kkm']);

        return min(100, max(0, $nilai));
    }
}
