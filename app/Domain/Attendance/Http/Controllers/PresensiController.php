<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\StatusPengakuan;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceAcknowledgement;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KodePindai;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Support\Qr\KodeQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presensi ruang - wireframe plate 12 dan 13.
 *
 * =====================================================================
 * {peserta} SELALU diperiksa ulang terhadap {ruang}
 * =====================================================================
 * Rute ini berbunyi `/pengawas/ruang/{ruang}/presensi/{peserta}`, dan
 * keduanya di-bind implisit. Itu berarti peramban bisa mengirim pasangan
 * yang TIDAK berhubungan: ruang miliknya sendiri, dengan id peserta milik
 * ruang lain.
 *
 * Setiap aksi karena itu dimulai dengan `milikRuang()` - satu pemeriksaan
 * yang menanyakan apakah peserta ini benar-benar duduk di ruang ini.
 * Tanpa itu, kriteria blueprint "pengawas R-02 tidak dapat memverifikasi
 * peserta R-03 meski menebak ID-nya" gagal dengan cara yang paling
 * sederhana: menebak ID-nya pada rute yang salah.
 *
 * ---------------------------------------------------------------------
 * Mengapa koreksi dipisah dari penandaan biasa
 * ---------------------------------------------------------------------
 * Selama sesi berjalan, menandai hadir adalah keputusan lapangan -
 * pengawas melihat sendiri orangnya berdiri di depan kursi. Setelah sesi
 * berakhir, mengubah presensi berarti menulis ulang fakta yang sudah
 * lewat, dan koreksi seperti itu menuntut alasan tertulis (plate 17).
 *
 * Yang membedakan keduanya bukan siapa pelakunya, melainkan apakah
 * sesinya masih berjalan. Karena itu pemeriksaannya ada di sini, dan
 * bukan di policy.
 */
class PresensiController
{
    public function __construct(
        private readonly KehadiranService $kehadiran,
        private readonly KodePindai $pindai,
        private readonly ActivityLogger $logger,
    ) {}

    /** Plate 12: daftar kursi. */
    public function index(ExamSessionRoom $ruang): Response
    {
        $konteks = KonteksRuang::dari($ruang);

        $records = $this->kehadiran->daftarRuang($ruang);

        // Nomor kursi datang dari service, bukan dari posisi barisnya.
        //
        // Sampai 2026-11-09 keduanya sama - `daftarRuang()` berurut abjad
        // nama dan nomornya `$i + 1` - sehingga "nomor kursi" dan "urutan
        // daftar" bisa berupa satu angka. Sejak nomornya dihitung atas ruang
        // EVENT, himpunannya tidak lagi sama dengan daftar satu ruang-sesi:
        // sesi yang hanya diikuti sebagian kelas memuat kursi 3, 7, 12 - dan
        // menulis `$i + 1` di situ akan menyebut kursi 1 untuk anak yang
        // duduk di kursi 3. Denah, tablo, dan papan ini harus menyebut angka
        // yang sama, dan yang berhak menentukannya adalah `NomorKursi`.
        $nomor = $this->kehadiran->nomorKursiRuang($ruang);

        // Peta sumber per penempatan - "pindai 07:32" / "pengawas 07:28"
        // pada baris kursi. Dibaca sekali untuk seluruh ruang, bukan per
        // baris: daftar ini 32 baris dan tiap baris akan menembak satu kueri.
        $sumber = $this->sumberPerPeserta($ruang);

        return Inertia::render('Pengawas/Presensi', [
            ...$this->kepala($ruang, $konteks),
            'rekap' => $this->kehadiran->rekap($ruang),
            'pindai' => $this->payloadPindai($ruang),
            'pengakuan' => $this->daftarPengakuan($ruang, $nomor),
            'kursi' => $records->values()->map(fn (AttendanceRecord $r) => [
                'record_id' => $r->id,
                'peserta_id' => $r->exam_participant_id,
                // Bukan kolom di basis data: nomor kursi DIHITUNG, dan
                // dihitung satu tempat - lihat catatan panjang di NomorKursi
                // tentang mengapa menyimpannya justru lebih buruk.
                'kursi' => $nomor[(int) $r->penempatan?->student_id] ?? null,
                'nama' => $r->penempatan?->student?->name ?? 'Peserta',
                // NIS datang dari profil siswa, bukan dari baris peserta
                // event: yang menyimpannya adalah StudentProfile, dan
                // menyalinnya ke peserta event berarti dua tempat yang bisa
                // berbeda diam-diam saat siswa pindah kelas.
                'nis' => $r->penempatan?->student?->studentProfile?->nis,
                'kelas' => $r->penempatan?->pesertaEvent?->kelas?->name,

                'status' => $r->attendance_status->value,
                'status_label' => $r->attendance_status->label(),
                'status_pendek' => $r->attendance_status->pendek(),
                'status_nada' => $r->attendance_status->nada(),

                // Kolom Pengerjaan berasal dari server dan tidak bisa
                // diketik siapa pun - plate 12 memisahkannya dari kolom
                // Presensi justru karena keduanya masalah yang berbeda.
                'pengerjaan' => $this->pengerjaan($r),
                'sisa' => $r->log->count() > 0 ? $r->log->count().' perubahan' : null,

                // Sumbernya, dan itu bukan kolom tersembunyi - plate C:
                // "anak saya hadir atas dasar apa" dijawab dari layar yang
                // sama. null berarti belum ada presensi sama sekali.
                'sumber' => $sumber[$r->exam_participant_id] ?? null,
            ])->all(),
        ]);
    }

    /** Plate 13: satu siswa, sebagai lapisan dari baris kursi. */
    public function satu(ExamSessionRoom $ruang, ExamParticipant $peserta): Response
    {
        $konteks = KonteksRuang::dari($ruang);
        $record = $this->milikRuang($ruang, $peserta);

        $log = $record->log()->orderByDesc('id')->limit(30)->get();

        return Inertia::render('Pengawas/PresensiSatu', [
            ...$this->kepala($ruang, $konteks),
            'record_id' => $record->id,
            'peserta_id' => $peserta->id,
            'nama' => $peserta->student?->name ?? 'Peserta',
            'nis' => $peserta->student?->studentProfile?->nis,
            'kelas' => $peserta->pesertaEvent?->kelas?->name,

            'status' => $record->attendance_status->value,
            'status_label' => $record->attendance_status->label(),
            'status_nada' => $record->attendance_status->nada(),

            // "Diverifikasi 07:42 oleh R-01 (Anda)" - plate 13. Jamnya dari
            // verified_at, pelakunya dari pelaku_label; keduanya dicatat di
            // baris yang sama oleh KehadiranService, sehingga kalimatnya
            // tidak bisa menyebut orang yang berbeda dari yang tercatat.
            'diverifikasi_jam' => $record->verified_at?->format('H:i'),
            'diverifikasi_oleh' => $record->pelaku_label,

            'pengerjaan' => $this->pengerjaan($record),

            'riwayat' => $log->map(fn ($l) => [
                'jam' => $l->created_at?->format('H:i'),
                'dari' => $l->old_status?->label(),
                'ke' => $l->new_status?->label(),
                'oleh' => $l->pelaku_label,
                'alasan' => $l->reason,
            ])->all(),

            'opsi' => StatusPresensi::options(),
        ]);
    }

    /**
     * Verifikasi satu peserta.
     *
     * Alasan TIDAK dituntut di sini, dan itu pembedaan plate 17: selama
     * sesinya berjalan, ini keputusan lapangan. Yang menuntutnya adalah
     * `simpanKoreksi()`.
     */
    public function verifikasi(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);

        $valid = $request->validate([
            'record_id' => ['required', 'integer'],
            'status' => ['required', 'string'],
            'alasan' => ['nullable', 'string', 'max:500'],
        ], [], ['record_id' => 'peserta', 'status' => 'status presensi']);

        $status = StatusPresensi::tryFrom($valid['status']);

        if ($status === null) {
            return back()->with('galat', 'Status presensi itu tidak dikenal.');
        }

        $record = $this->kehadiran->daftarRuang($ruang)
            ->firstWhere('id', $valid['record_id']);

        if ($record === null) {
            // Bukan 403: peserta itu memang tidak ada di ruang ini, dan
            // membedakannya dari "tidak ada sama sekali" akan membuktikan
            // bahwa barisnya ada di tempat lain.
            abort(404);
        }

        $this->kehadiran->catat($record, $status, $konteks, $valid['alasan'] ?? null);

        return back()->with('sukses', 'Presensi tersimpan.');
    }

    /**
     * Verifikasi massal - plate 12, "Tandai semua hadir".
     *
     * Satu baris log per peserta, meski satu tombol yang menekannya. Lihat
     * catatan panjang di KehadiranService tentang mengapa.
     */
    public function massal(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);

        $valid = $request->validate([
            'record_ids' => ['required', 'array', 'min:1'],
            'record_ids.*' => ['integer'],
            'status' => ['required', 'string'],
        ], [], ['record_ids' => 'peserta', 'status' => 'status presensi']);

        $status = StatusPresensi::tryFrom($valid['status']);

        if ($status === null) {
            return back()->with('galat', 'Status presensi itu tidak dikenal.');
        }

        // Penyaringan ruang dijalankan LAGI di dalam service, bukan hanya
        // dipercaya dari sini - daftar id datang dari peramban.
        $berubah = $this->kehadiran->verifikasiMassal(
            $ruang,
            $valid['record_ids'],
            $status,
            $konteks,
        );

        $this->logger->log('Verifikasi presensi massal', $ruang, [
            'ruang' => $konteks->kodeRuang(),
            'status' => $status->value,
            'diminta' => count($valid['record_ids']),
            'berubah' => $berubah,
        ]);

        return back()->with('sukses', $berubah === 0
            ? 'Tidak ada yang berubah - semuanya sudah berstatus itu.'
            : "{$berubah} peserta ditandai {$status->label()}.");
    }

    /** Plate 17: laman koreksi. */
    public function koreksi(ExamSessionRoom $ruang): Response
    {
        $konteks = KonteksRuang::dari($ruang);

        $records = $this->kehadiran->daftarRuang($ruang);
        $nomor = $this->kehadiran->nomorKursiRuang($ruang);

        return Inertia::render('Pengawas/Koreksi', [
            ...$this->kepala($ruang, $konteks),

            // Chip plate 17 menyatakan keadaan sesinya, karena itulah yang
            // mengubah bobot perbuatannya: "sesi sudah berakhir" berarti
            // yang berikutnya adalah koreksi, bukan penandaan.
            'sesi_berakhir' => $this->sesiBerakhir($ruang),

            'kursi' => $records->values()->map(fn (AttendanceRecord $r) => [
                'record_id' => $r->id,
                'peserta_id' => $r->exam_participant_id,
                // Sumber yang sama dengan laman presensi - keduanya memakai
                // `KehadiranService::nomorKursiRuang()` - sehingga nomor di
                // laman koreksi tidak bergeser dari nomor yang dibaca
                // pengawas di daftar hadir.
                'kursi' => $nomor[(int) $r->penempatan?->student_id] ?? null,
                'nama' => $r->penempatan?->student?->name ?? 'Peserta',
                'nis' => $r->penempatan?->student?->studentProfile?->nis,
                'status' => $r->attendance_status->value,
                'status_label' => $r->attendance_status->label(),
                'status_nada' => $r->attendance_status->nada(),
                'perubahan' => $r->log->count(),
            ])->all(),

            'opsi' => StatusPresensi::options(),
        ]);
    }

    /**
     * Simpan satu koreksi - dan ALASANNYA WAJIB.
     *
     * Kolom wajib, bukan opsional: koreksi presensi dapat mengubah siapa
     * yang boleh mengikuti ujian, dan tanpa alasan tertulis catatan itu
     * tidak dapat dipertanggungjawabkan kepada siapa pun di kemudian hari
     * (plate 17).
     */
    public function simpanKoreksi(
        ExamSessionRoom $ruang,
        ExamParticipant $peserta,
        Request $request,
    ): RedirectResponse {
        $konteks = KonteksRuang::dari($ruang);
        $record = $this->milikRuang($ruang, $peserta);

        $valid = $request->validate([
            'status' => ['required', 'string'],
            'alasan' => ['required', 'string', 'min:4', 'max:500'],
        ], [
            'alasan.required' => 'Alasan koreksi wajib diisi.',
            'alasan.min' => 'Alasan koreksi terlalu pendek untuk bisa dipertanggungjawabkan.',
        ], ['status' => 'status presensi', 'alasan' => 'alasan koreksi']);

        $status = StatusPresensi::tryFrom($valid['status']);

        if ($status === null) {
            return back()->with('galat', 'Status presensi itu tidak dikenal.');
        }

        $this->kehadiran->catat($record, $status, $konteks, $valid['alasan']);

        $this->logger->log('Koreksi presensi', $record, [
            'ruang' => $konteks->kodeRuang(),
            'peserta' => $peserta->student?->name,
            'ke' => $status->value,
            'alasan' => $valid['alasan'],
        ]);

        return back()->with('sukses', 'Koreksi tersimpan sebagai perubahan baru.');
    }

    /**
     * Kembalikan satu peserta ke `pending`.
     *
     * Ada karena salah tandai pasti terjadi. Yang TIDAK ada adalah
     * menghapus jejaknya: pengembalian menulis baris log yang menyatakan
     * status sebelumnya, sehingga "pernah ditandai hadir lalu dikembalikan"
     * tetap terbaca.
     */
    public function kembalikan(
        ExamSessionRoom $ruang,
        ExamParticipant $peserta,
        Request $request,
    ): RedirectResponse {
        $konteks = KonteksRuang::dari($ruang);
        $record = $this->milikRuang($ruang, $peserta);

        $valid = $request->validate([
            'alasan' => ['required', 'string', 'min:4', 'max:500'],
        ], ['alasan.required' => 'Alasan pengembalian wajib diisi.'], ['alasan' => 'alasan']);

        $this->kehadiran->kembalikan($record, $konteks, $valid['alasan']);

        return back()->with('sukses', 'Presensi dikembalikan ke belum diverifikasi.');
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Payload kotak pindai - plate A1/A2.
     *
     * =====================================================================
     * Kode diterbitkan SETIAP KALI laman dimuat, dan itu bukan pemborosan
     * =====================================================================
     * `code_hash` satu arah, jadi kode yang sudah terbit tidak bisa dibaca
     * kembali. Satu-satunya saat laman memegang kode mentahnya adalah saat
     * ia menerbitkannya sendiri - karena itu setiap muat menerbitkan satu
     * baris baru, dan yang sebelumnya mati 60 detik kemudian.
     *
     * Rotasi berikutnya datang dari endpoint JSON yang dipanggil pitanya
     * saat habis, bukan dari penyegaran seluruh laman: pengawas yang sedang
     * menandai presensi tidak boleh kehilangan posisinya tiap menit.
     */
    private function payloadPindai(ExamSessionRoom $ruang): ?array
    {
        // Kode hanya berguna bila sesinya sedang berjalan. Kotak yang tampil
        // dengan kode sah di sesi yang belum dibuka akan membuat siswa
        // memindai lalu ditolak "sesi belum dibuka" - dan siswa itu akan
        // menuduh kodenya, bukan jadwalnya.
        $sesi = $ruang->session;

        if ($sesi === null || ! $sesi->status->aktif()) {
            return null;
        }

        $terbit = $this->pindai->terbitkan($ruang);
        $kode = $terbit['kode'];
        $url = route('siswa.hadir', ['kode' => $kode]);

        return [
            'kode' => $kode,
            'url' => $url,
            'qr' => KodeQr::dataUri($url, 320),
            'sisa' => $terbit['baris']->sisaDetik(),
            'umur' => KodePindai::UMUR_DETIK,
        ];
    }

    /**
     * Daftar pengakuan yang belum dikonfirmasi - plate A3.
     *
     * Hanya `menunggu`: baris yang sudah dikonfirmasi bukan lagi urusan
     * lembar ini, dan menampilkannya di sini akan membuat tombol massalnya
     * tampak belum mengerjakan apa pun.
     *
     * @param  array<int, int>  $nomor
     */
    private function daftarPengakuan(ExamSessionRoom $ruang, array $nomor): array
    {
        $baris = AttendanceAcknowledgement::query()
            ->where('status', StatusPengakuan::Menunggu)
            ->whereHas('penempatan', fn ($q) => $q->where('exam_session_room_id', $ruang->id))
            ->with([
                'penempatan.student:id,name',
                'penempatan.student.studentProfile:id,user_id,nis',
                'penempatan.pesertaEvent.kelas:id,name',
                'penempatan.session:id,access_opens_at',
            ])
            ->get()
            ->sortBy(fn (AttendanceAcknowledgement $a) => $a->penempatan?->student?->name)
            ->values();

        return $baris->map(function (AttendanceAcknowledgement $a) use ($nomor) {
            $penempatan = $a->penempatan;
            $status = $this->pindai->statusDariPindai($a);

            return [
                'id' => $a->id,
                'peserta_id' => $a->exam_participant_id,
                'kursi' => $nomor[(int) $penempatan?->student_id] ?? null,
                'nama' => $penempatan?->student?->name ?? 'Peserta',
                'nis' => $penempatan?->student?->studentProfile?->nis,
                'kelas' => $penempatan?->pesertaEvent?->kelas?->name,
                'jam' => $a->scanned_at?->format('H:i'),
                // Status yang AKAN dicatat, bukan yang sudah dicatat - dan
                // dihitung dari jam PINDAI, bukan jam konfirmasi (plate C).
                'akan' => $status->value,
                'akan_label' => $status->pendek(),
                'akan_nada' => $status->nada(),
                // Centang bawaan hanya pada yang masih di dalam batas masuk.
                // Kalau semua tercentang sejak awal, tombol massal berubah
                // menjadi "catat apa pun yang datang" - plate A3.
                'tepat' => $status === StatusPresensi::Hadir,
            ];
        })->all();
    }

    /**
     * Sumber presensi tiap penempatan di ruang ini.
     *
     * `pindai` bila ada pengakuan yang sudah dikonfirmasi - jamnya jam
     * PINDAI, karena itulah yang menentukan statusnya. `pengawas` bila
     * presensinya ditandai langsung dari daftar kursi, dan jamnya jam
     * verifikasi.
     *
     * Keduanya dibaca sekali untuk seluruh ruang: 32 baris dengan satu
     * kueri per baris adalah 32 kueri yang tidak perlu.
     *
     * @return array<int, array{jenis: string, jam: string|null}>
     */
    private function sumberPerPeserta(ExamSessionRoom $ruang): array
    {
        $pesertaIds = ExamParticipant::query()
            ->where('exam_session_room_id', $ruang->id)
            ->pluck('id');

        if ($pesertaIds->isEmpty()) {
            return [];
        }

        $dikonfirmasi = AttendanceAcknowledgement::query()
            ->whereIn('exam_participant_id', $pesertaIds)
            ->where('status', StatusPengakuan::Dikonfirmasi)
            ->get()
            ->keyBy('exam_participant_id');

        $terverifikasi = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $pesertaIds)
            ->whereNotNull('verified_at')
            ->get()
            ->keyBy('exam_participant_id');

        $sumber = [];

        foreach ($pesertaIds as $id) {
            $pengakuan = $dikonfirmasi->get($id);

            if ($pengakuan !== null) {
                // Jam PINDAI, bukan jam konfirmasi - itulah angka yang
                // dibaca siswa di layarnya sendiri dan yang menentukan
                // hadir/terlambat.
                $sumber[(int) $id] = [
                    'jenis' => 'pindai',
                    'jam' => $pengakuan->scanned_at?->format('H:i'),
                ];

                continue;
            }

            $record = $terverifikasi->get($id);

            if ($record !== null) {
                $sumber[(int) $id] = [
                    'jenis' => 'pengawas',
                    'jam' => $record->verified_at?->format('H:i'),
                ];
            }
        }

        return $sumber;
    }

    /**
     * Kode pindai berikutnya, untuk rotasi 60 detik - plate A1/A2.
     *
     * Dijawab JSON, bukan redirect: laman memanggilnya dari latar belakang
     * setiap kali pitanya habis, dan redirect akan memuat ulang seluruh
     * laman - tepat saat pengawas sedang menandai kursi.
     */
    public function rotasiPindai(ExamSessionRoom $ruang): JsonResponse
    {
        KonteksRuang::dari($ruang);

        $sesi = $ruang->session;

        if ($sesi === null || ! $sesi->status->aktif()) {
            return response()->json([
                'galat' => 'Sesi ini belum berjalan. Kode pindai hanya terbit saat sesi dibuka.',
            ], 409);
        }

        $terbit = $this->pindai->terbitkan($ruang);
        $kode = $terbit['kode'];
        $url = route('siswa.hadir', ['kode' => $kode]);

        return response()->json([
            'kode' => $kode,
            'url' => $url,
            'qr' => KodeQr::dataUri($url, 320),
            'sisa' => $terbit['baris']->sisaDetik(),
        ]);
    }

    /**
     * Konfirmasi sekelompok pengakuan - plate A3.
     *
     * Status tiap baris DITURUNKAN dari jam pindainya sendiri, sehingga satu
     * tombol bisa mencatat sebagian hadir dan sebagian terlambat. Satu baris
     * log per siswa, seperti verifikasi massal yang sudah ada.
     */
    public function konfirmasi(ExamSessionRoom $ruang, Request $request): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);

        $valid = $request->validate([
            'pengakuan_ids' => ['required', 'array', 'min:1'],
            'pengakuan_ids.*' => ['integer'],
        ], [], ['pengakuan_ids' => 'pengakuan']);

        $hasil = $this->pindai->konfirmasi($ruang, $valid['pengakuan_ids'], $konteks);

        $this->logger->log('Konfirmasi pengakuan presensi', $ruang, [
            'ruang' => $konteks->kodeRuang(),
            'diminta' => count($valid['pengakuan_ids']),
            'berubah' => $hasil['berubah'],
            'dilewati' => $hasil['dilewati'],
        ]);

        if ($hasil['berubah'] === 0 && $hasil['dilewati'] === 0) {
            return back()->with('galat', 'Tidak ada pengakuan yang bisa dikonfirmasi.');
        }

        return back()->with('sukses', $hasil['berubah'] === 0
            ? 'Pengakuan sudah dikonfirmasi - status presensinya tidak berubah.'
            : "{$hasil['berubah']} peserta dicatat dari pindai.");
    }

    /**
     * Keadaan pengerjaan untuk satu baris presensi.
     *
     * Fase 6 hanya MEMBACA `exam_attempts`; yang menulisnya adalah Fase 7.
     * Karena itu seluruh cabang di bawah mengembalikan "belum" selama
     * tabelnya masih kosong - dan bentuknya sudah benar, sehingga Fase 7
     * tidak perlu mengubah laman ini satu baris pun.
     *
     * @return array<string, mixed>
     */
    private function pengerjaan(AttendanceRecord $record): array
    {
        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $record->exam_participant_id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return [
                'ada' => false,
                'label' => 'Belum mulai',
                'nada' => 'mute',
                'terhubung' => null,
                'pelanggaran' => 0,
                'terakhir' => null,
            ];
        }

        return [
            'ada' => true,
            'label' => $attempt->labelStatus(),
            'nada' => $attempt->nadaStatus(),
            'terhubung' => $attempt->terhubung(),
            'pelanggaran' => $attempt->violation_count,
            'terakhir' => $attempt->last_seen_at?->format('H:i'),
        ];
    }

    /**
     * Berkas presensi satu peserta, dipastikan duduk di ruang ini.
     *
     * Pemanggilnya `firstOrFail`-nya sendiri: peserta yang tidak ditemukan,
     * atau yang ditemukan di ruang lain, sama-sama berakhir sebagai 404.
     */
    private function milikRuang(ExamSessionRoom $ruang, ExamParticipant $peserta): AttendanceRecord
    {
        if ((int) $peserta->exam_session_room_id !== (int) $ruang->id) {
            abort(404);
        }

        return AttendanceRecord::query()
            ->where('exam_participant_id', $peserta->id)
            ->firstOrFail();
    }

    private function sesiBerakhir(ExamSessionRoom $ruang): bool
    {
        $sesi = $ruang->session;

        if ($sesi === null) {
            return true;
        }

        return in_array($sesi->status, [StatusSesi::Selesai, StatusSesi::Batal], true)
            || now()->gt($sesi->hard_end_at);
    }

    /** @return array<string, mixed> */
    private function kepala(ExamSessionRoom $ruang, KonteksRuang $konteks): array
    {
        return [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $konteks->kodeRuang(),
                'nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'jam' => $ruang->session === null
                    ? null
                    : $ruang->session->access_opens_at->format('H:i').'–'.$ruang->session->hard_end_at->format('H:i'),
                'berakhir_pada' => $ruang->session?->hard_end_at?->toIso8601String(),
            ],
            'pelaku' => $konteks->jenisPelaku(),
        ];
    }
}
