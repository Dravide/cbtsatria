<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportFile;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Tenancy\Models\School;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Laman pantau wali per kelas - `wireframe-pantau-wali.html`.
 *
 * =====================================================================
 * Batas isinya, dan mengapa berbeda dari tiga laman publik lainnya
 * =====================================================================
 * `/papan`, `/pantau`, dan `/p/{slug}` semuanya menyebut ruang, pengawas,
 * dan angka. Tidak satu pun menyebut SISWA. Laman ini menyebut nama anak,
 * jam ia masuk, dan berapa lama ia mengerjakan - dan alamatnya di luar
 * `auth`.
 *
 * Yang menjadikan itu bisa dipertanggungjawabkan bukan isinya, melainkan
 * penjaganya, dan ketiganya ditegakkan DI SINI - bukan di controller, bukan
 * di halaman:
 *
 *   1. `wali_slug` NULL berarti kelasnya tidak punya alamat sama sekali;
 *   2. daftar nama boleh terbuka, tetapi JAM hanya sesudah kode kelas benar;
 *   3. NILAI tidak pernah dikirim, dalam bentuk apa pun.
 *
 * Poin ketiga yang paling mudah dilanggar tanpa sengaja. `exam_results`
 * sudah terisi begitu attempt dikumpulkan, dan satu `with('hasil')` yang
 * ditambahkan dengan niat baik akan membocorkan nilai 1.265 anak ke
 * internet sebelum dipublikasikan. Karena itu daftar kolom yang boleh
 * keluar ditulis sebagai konstanta di sini, dan tidak ada satu tempat lain
 * yang memuatnya.
 *
 * =====================================================================
 * Siswa dibaca lewat PENEMPATAN, bukan lewat `class_enrollments`
 * =====================================================================
 * Pertanyaan laman ini adalah "anak ini ikut ujian apa, dan sudah sampai
 * mana". Itu dijawab `exam_participants` + `exam_session_id`, bukan daftar
 * anggota kelas. Membacanya dari kelas saja akan menampilkan siswa yang
 * tidak diujikan, dan menyembunyikan siswa yang dipindah susulan.
 */
class PemantauWali
{
    /**
     * Kolom User yang boleh keluar - NAMA saja.
     *
     * Ditulis sebagai konstanta, bukan sebagai string di dalam query: yang
     * membaca berkas ini harus bisa memastikan dalam satu pandangan bahwa
     * `username`, `email`, dan `phone` tidak pernah ikut. Username hanya
     * berbeda satu digit dari NIS, dan ia kunci masuk ujian.
     */
    private const KOLOM_SISWA = 'users.id,users.name';

    /** Sesi yang sudah lewat sekian menit tetap ditampilkan sebagai "baru selesai". */
    private const MENIT_JEDA_SELESAI = 240;

    /**
     * Kelas-kelas yang dibuka untuk wali di satu sekolah.
     *
     * Hanya kelas yang `wali_slug`-nya terisi. Kelas yang belum dinyalakan
     * TIDAK muncul dengan gembok - alamat yang tidak pernah lahir lebih
     * kuat daripada alamat yang menolak.
     *
     * @return array<int, array<string, mixed>>
     */
    public function kelas(School $sekolah): array
    {
        $kelas = SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereNotNull('wali_slug')
            ->orderBy('name')
            ->get(['id', 'name', 'wali_slug']);

        if ($kelas->isEmpty()) {
            return [];
        }

        // Sesi aktif per kelas, satu query - bukan satu query per kelas.
        $sesi = $this->sesiAktif($sekolah);

        $idKelas = $kelas->pluck('id')->all();

        $adaUjian = ExamParticipant::query()
            ->withoutGlobalScopes()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->whereHas('pesertaEvent', fn ($q) => $q->whereIn('class_id', $idKelas))
            ->with('pesertaEvent:id,class_id')
            ->get()
            ->groupBy(fn (ExamParticipant $p) => (int) $p->pesertaEvent?->class_id)
            ->map(fn (Collection $c) => $c->count());

        return $kelas->map(fn (SchoolClass $k) => [
            'id' => (int) $k->id,
            'nama' => $k->name,
            'slug' => $k->wali_slug,
            'peserta' => (int) ($adaUjian[$k->id] ?? 0),
            'berjalan' => $adaUjian->has($k->id),
        ])->all();
    }

    /**
     * Daftar siswa satu kelas - TANPA jam, sampai kodenya benar.
     *
     * Yang dikirim hanya nama, ruang, dan kursi. Cukup untuk menjawab "anak
     * saya di ruang mana"; tidak cukup untuk memberitahu siapa pun tentang
     * jam masuk atau riwayat kehadiran anak orang lain.
     *
     * @return array{kelas: array<string, mixed>, sesi: array<string, mixed>|null, siswa: array<int, array<string, mixed>>}
     */
    public function ringkas(School $sekolah, SchoolClass $kelas): array
    {
        $sesi = $this->sesiKelas($sekolah, $kelas);
        $penempatan = $this->siswa($sekolah, $kelas, $sesi);
        $kursi = $this->kursi($penempatan);

        return [
            'kelas' => [
                'id' => (int) $kelas->id,
                'nama' => $kelas->name,
            ],
            'sesi' => $sesi === null ? null : $this->ringkasSesi($sesi),
            'siswa' => $penempatan
                ->map(fn (ExamParticipant $p) => [
                    'id' => (int) $p->id,
                    'nama' => $p->student?->name ?? '—',
                    'ruang' => $p->room?->eventRoom?->room?->code,
                    'kursi' => $kursi[(int) $p->student_id] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Rincian penuh satu kelas - HANYA sesudah kode benar.
     *
     * @return array<string, mixed>
     */
    public function rincian(School $sekolah, SchoolClass $kelas): array
    {
        $sesi = $this->sesiKelas($sekolah, $kelas);
        $penempatan = $this->siswa($sekolah, $kelas, $sesi);

        $idPenempatan = $penempatan->pluck('id')->all();

        $attempt = ExamAttempt::query()
            ->withoutGlobalScopes()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn (Collection $c) => $c->last());

        $presensi = AttendanceRecord::query()
            ->withoutGlobalScopes()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->get()
            ->keyBy('exam_participant_id');

        $sekarang = now();
        $kursi = $this->kursi($penempatan);

        $baris = $penempatan->map(fn (ExamParticipant $p) => $this->satuSiswa(
            $p,
            $attempt->get($p->id),
            $presensi->get($p->id),
            $sesi,
            $sekarang,
            $kursi[(int) $p->student_id] ?? null,
        ))->values()->all();

        return [
            'kelas' => ['id' => (int) $kelas->id, 'nama' => $kelas->name],
            'sesi' => $sesi === null ? null : $this->ringkasSesi($sesi),
            'rekap' => $this->rekap($baris),
            'siswa' => $baris,
            'pengawas' => $sesi === null ? [] : $this->pengawas($sesi),
            // Foto dikirim sebagai DAFTAR KEBERADAAN, bukan URL - lihat
            // `foto()`. Rute fotonya sendiri yang memeriksa kode.
            'foto' => $sesi === null ? [] : $this->foto($sesi, (int) $kelas->id),
            'diperbarui' => $sekarang->format('H:i'),
        ];
    }

    /**
     * Foto berita acara satu ruang-sesi.
     *
     * Dikembalikan sebagai baris berkas, BUKAN sebagai URL: yang menyajikan
     * berkasnya adalah rute tersendiri yang memeriksa kode lebih dulu.
     * Mengirim URL di props akan membuat foto bisa dibuka dengan menebak
     * id berkas, karena rute foto sendiri tidak tahu kode apa pun.
     *
     * @return array<int, array{id: int, diunggah: ?string, mime: ?string, lebar_sisi: string}>
     */
    private function foto(ExamSession $sesi, ?int $kelasId): array
    {
        if ($kelasId === null) {
            return [];
        }

        // Hanya ruang-sesi yang benar-benar memuat siswa kelas ini. Ruang
        // sesi lain pada sesi yang sama memuat anak kelas lain - dan foto
        // mereka bukan urusan orang tua di sini.
        $idRuang = ExamParticipant::query()
            ->withoutGlobalScopes()
            ->where('exam_session_id', $sesi->id)
            ->whereHas('pesertaEvent', fn ($q) => $q->where('class_id', $kelasId))
            ->pluck('exam_session_room_id')
            ->filter()
            ->unique()
            ->all();

        if ($idRuang === []) {
            return [];
        }

        return SessionReportFile::query()
            ->withoutGlobalScopes()
            ->whereHas('laporan', fn ($q) => $q
                ->where('school_id', $sesi->school_id)
                ->whereIn('exam_session_room_id', $idRuang)
                // Laporan `draft` masih bisa berubah, dan foto di dalamnya
                // belum tentu yang disahkan pengawas.
                ->where('status', SessionReport::STATUS_FINAL))
            // `sesudah` saja: sejak 2026-11-12 lamannya hanya meminta satu
            // foto, dan foto ruang kosong tidak menjawab pertanyaan orang tua.
            ->where('sisi', 'sesudah')
            ->where('mime', 'like', 'image/%')
            ->orderByDesc('diunggah_pada')
            ->get(['id', 'diunggah_pada', 'mime', 'sisi'])
            ->map(fn (SessionReportFile $f) => [
                'id' => (int) $f->id,
                'diunggah' => $f->diunggah_pada?->format('H:i'),
                'mime' => $f->mime,
                'sisi' => 'saat ujian',
            ])
            ->all();
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Sesi yang dijawab laman ini.
     *
     * Aturan yang sama persis dengan `PapanPengawas::sesiAktif()`: berjalan
     * lebih dulu, lalu yang terdekat, dan `hard_end_at >= now()` - bukan
     * tanggal. Menyaring dengan tanggal menampilkan sesi kemarin pada pukul
     * 23:50 dan menyembunyikan sesi yang lewat tengah malam.
     */
    private function sesiAktif(School $sekolah): Collection
    {
        $statusAktif = array_map(fn (EventStatus $s) => $s->value, EventStatus::belumSelesai());

        $layak = fn ($q) => $q
            ->where('school_id', $sekolah->id)
            ->whereIn('status', $statusAktif)
            ->where('hard_end_at', '>=', now()->subMinutes(self::MENIT_JEDA_SELESAI));

        $berjalan = ExamSession::query()
            ->withoutGlobalScopes()
            ->whereHas('exam.event', $layak)
            ->where('status', StatusSesi::Berjalan->value)
            ->with(['exam:id,title,subject_id,event_id', 'exam.subject:id,name'])
            ->orderBy('access_opens_at')
            ->get();

        if ($berjalan->isNotEmpty()) {
            return $berjalan;
        }

        return ExamSession::query()
            ->withoutGlobalScopes()
            ->whereHas('exam.event', $layak)
            ->with(['exam:id,title,subject_id,event_id', 'exam.subject:id,name'])
            ->orderByDesc('hard_end_at')
            ->limit(10)
            ->get();
    }

    /**
     * Sesi yang sedang/baru berlangsung untuk satu kelas.
     *
     * Dipilih dari sesi aktif yang benar-benar memuat siswa kelas ini -
     * bukan sesi pertama sekolah. Kelas yang tidak ikut ujian pagi ini
     * harus melihat sesinya sendiri, atau kalimat "belum ada ujian".
     */
    private function sesiKelas(School $sekolah, SchoolClass $kelas): ?ExamSession
    {
        return $this->sesiAktif($sekolah)
            ->first(fn (ExamSession $s) => ExamParticipant::query()
                ->withoutGlobalScopes()
                ->where('exam_session_id', $s->id)
                ->whereHas('pesertaEvent', fn ($q) => $q->where('class_id', $kelas->id))
                ->exists());
    }

    /** @return Collection<int, ExamParticipant> */
    private function siswa(School $sekolah, SchoolClass $kelas, ?ExamSession $sesi): Collection
    {
        if ($sesi === null) {
            return collect();
        }

        return ExamParticipant::query()
            ->withoutGlobalScopes()
            // Join `users` untuk mengurutkan menurut NAMA - yang dibaca orang
            // tua adalah daftar yang urutannya masuk akal, dan nomor kursi
            // menyusul dari nama itu.
            //
            // SELURUH kolom yang mungkin ambigu dikualifikasi. Tanpa itu,
            // `where('school_id', ...)` gagal dengan "Column 'school_id' in
            // where clause is ambiguous" - dan pesannya menyebut kolom,
            // bukan join-nya.
            ->join('users', 'users.id', '=', 'exam_participants.student_id')
            ->where('exam_participants.school_id', $sekolah->id)
            ->where('exam_participants.exam_session_id', $sesi->id)
            ->where('exam_participants.status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereHas('pesertaEvent', fn ($q) => $q->where('class_id', $kelas->id))
            ->with([
                // Kolomnya disebut satu per satu - lihat `KOLOM_SISWA`.
                'student:'.self::KOLOM_SISWA,
                'room.eventRoom.room:id,code,name',
            ])
            ->orderBy('users.name')
            ->select('exam_participants.*')
            ->get();
    }

    /**
     * Nomor kursi SATU kelas, sekaligus.
     *
     * `NomorKursi::satu()` per anak berarti satu kueri per baris untuk
     * menghitung himpunan yang sama. Yang dipakai di sini adalah bentuk
     * borongannya per ruang-event, lalu dipetakan lewat `student_id` -
     * kunci yang sama dengan yang dipakai `petaRuangEvent()` sendiri.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @return array<int, int> student_id => kursi
     */
    private function kursi(Collection $penempatan): array
    {
        $perRuang = $penempatan
            ->map(fn (ExamParticipant $p) => (int) ($p->room?->eventRoom?->room?->id ?? 0))
            ->filter()
            ->unique();

        $peta = [];

        foreach ($perRuang as $idRuang) {
            $peta += app(NomorKursi::class)->petaRuangEvent($idRuang);
        }

        return $peta;
    }

    /**
     * @return array<string, mixed>
     */
    private function satuSiswa(
        ExamParticipant $p,
        ?ExamAttempt $attempt,
        ?AttendanceRecord $presensi,
        ?ExamSession $sesi,
        CarbonInterface $sekarang,
        ?int $kursi,
    ): array {
        $status = $attempt?->status;
        $mulai = $attempt?->started_at;

        $selesai = $status !== null && in_array($status, [
            ExamAttempt::STATUS_DIKIRIM,
            ExamAttempt::STATUS_KEDALUWARSA,
        ], true);

        // "Mengerjakan" dihitung sampai SEKARANG untuk attempt yang masih
        // berjalan, dan sampai `submitted_at` untuk yang sudah selesai.
        // Masa terkunci DIKURANGI, dan itu bukan kerapian: angka total yang
        // menyatukan keduanya berbohong tentang anak yang paling butuh
        // penjelasan.
        $akhir = match (true) {
            $status === ExamAttempt::STATUS_DIKIRIM => $attempt->submitted_at ?? $sekarang,
            $status === ExamAttempt::STATUS_KEDALUWARSA => $attempt->deadline_at ?? $sekarang,
            $mulai !== null => $sekarang,
            default => null,
        };

        $menitKerja = ($mulai !== null && $akhir !== null)
            ? max(0, (int) $mulai->diffInMinutes($akhir))
            : null;

        $menitTerkunci = ($attempt?->locked_at !== null && $status === ExamAttempt::STATUS_DIKUNCI)
            ? max(0, (int) $attempt->locked_at->diffInMinutes($sekarang))
            : 0;

        $sisa = ($attempt?->deadline_at !== null && ! $selesai)
            ? max(0, (int) $sekarang->diffInMinutes($attempt->deadline_at, false))
            : null;

        return [
            'id' => (int) $p->id,
            'nama' => $p->student?->name ?? '—',
            'ruang' => $p->room?->eventRoom?->room?->code,
            'kursi' => $kursi,

            // ----------------------------------------------------------
            // Presensi - dan keadaan yang tidak punya jam sama sekali
            // ----------------------------------------------------------
            // Anak yang tercatat tidak hadir TIDAK mendapat angka nol di
            // kolom durasi. "0 menit" dibaca sebagai "tidak mau
            // mengerjakan", bukan sebagai "tidak ada di ruangan".
            'hadir' => $presensi?->attendance_status?->value,
            'hadir_label' => $presensi?->attendance_status?->label(),
            'absen' => $presensi?->verified_at?->format('H:i'),
            'masuk_gerbang' => $presensi?->attendance_status?->membukaGerbang() ?? false,

            'status' => $status,
            'status_label' => $attempt?->labelStatus(),
            'mulai' => $mulai?->format('H:i'),
            'selesai' => $selesai ? ($attempt->submitted_at?->format('H:i') ?? '—') : null,
            'menit_kerja' => $menitKerja,
            'menit_terkunci' => $menitTerkunci > 0 ? $menitTerkunci : null,
            'sisa_menit' => $sisa,

            // Jam buka sesi: satu-satunya yang bisa dikatakan tentang anak
            // yang belum masuk sama sekali.
            'jam_sesi' => $sesi === null
                ? null
                : $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
        ];
    }

    /**
     * Rekap kelas - angka saja, tanpa nama siapa pun.
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<string, int>
     */
    private function rekap(array $baris): array
    {
        return [
            'total' => count($baris),
            'hadir' => count(array_filter($baris, fn (array $b) => $b['masuk_gerbang'])),
            'mengerjakan' => count(array_filter(
                $baris,
                fn (array $b) => in_array($b['status'], [
                    ExamAttempt::STATUS_BERJALAN,
                    ExamAttempt::STATUS_DIKUNCI,
                ], true),
            )),
            'selesai' => count(array_filter(
                $baris,
                fn (array $b) => in_array($b['status'], [
                    ExamAttempt::STATUS_DIKIRIM,
                    ExamAttempt::STATUS_KEDALUWARSA,
                ], true),
            )),
        ];
    }

    /**
     * Pengawas setiap ruang pada sesi ini.
     *
     * Nama pengawas SUDAH publik di `/papan/{slug}` sejak 2026-11-14, jadi
     * menambahkannya di sini tidak membuka apa pun yang belum terbuka. Yang
     * ditambahkan hanya kolom `name` - kolom pengguna lain tidak ikut.
     *
     * @return array<int, array{ruang: ?string, nama: string}>
     */
    private function pengawas(ExamSession $sesi): array
    {
        return $sesi->rooms()
            ->withoutGlobalScopes()
            ->with([
                'eventRoom.room:id,code',
                'supervisors.supervisor:id,name',
            ])
            ->get()
            ->flatMap(fn ($r) => $r->supervisors->map(fn (SupervisionAssignment $t) => [
                'ruang' => $r->eventRoom?->room?->code,
                'nama' => $t->supervisor?->name ?? '—',
            ]))
            ->filter(fn (array $p) => $p['nama'] !== '—')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ringkasSesi(ExamSession $sesi): array
    {
        return [
            'id' => (int) $sesi->id,
            'label' => $sesi->label(),
            'ujian' => $sesi->exam?->title,
            'mapel' => $sesi->exam?->subject?->name,
            'jam' => $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
            'status' => $sesi->status->value,
        ];
    }
}
