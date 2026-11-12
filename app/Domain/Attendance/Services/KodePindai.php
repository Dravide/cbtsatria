<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\StatusPengakuan;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceAcknowledgement;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceScanCode;
use App\Domain\Attendance\Support\HasilPindai;
use App\Domain\Attendance\Support\PenolakanPindai;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kode pindai presensi, pengakuan siswa, dan konfirmasi pengawas.
 *
 * PRD §10, wireframe-presensi-qr.html plate A1-B6 dan bagian C.
 *
 * =====================================================================
 * EMPAT hal, dan hanya yang ketiga yang menulis presensi
 * =====================================================================
 *   terbitkan()   - kode 60 detik yang tampil di laman presensi.
 *   periksa()     - memeriksa satu pindai. TIDAK menulis apa pun.
 *   akui()        - menulis `attendance_acknowledgements`, sesudah siswa
 *                   menekan "Saya hadir di ruang ini".
 *   konfirmasi()  - pengawas menekan tombol; inilah yang memanggil
 *                   `KehadiranService::catat()` per baris.
 *
 * Pemisahan `periksa()` dari `akui()` bukan kerapian. Laman `/hadir/{kode}`
 * diperiksa saat DIBUKA dan diakui saat tombolnya DITEKAN, dan kode 60
 * detik bisa mati di antara keduanya - siswa yang memindai pada detik ke-55
 * akan ditolak pada detik ke-57 kalau tombolnya memeriksa ulang kodenya.
 * Yang menentukan sah tidaknya pindai adalah saat MEMINDAI, dan itulah yang
 * disimpan `akui()`.
 *
 * Kalau siswa bisa menulis presensi, satu ponsel di luar ruang bisa
 * mengubah kehadiran seluruh kelas - dan presensi adalah dasar gerbang ujian
 * serta dasar berita acara. Karena itu yang ditulis di sini hanya pengakuan.
 *
 * =====================================================================
 * Urutan pemeriksaan: kode, penempatan, sesi, presensi
 * =====================================================================
 * Dan urutan itu bukan gaya. Kalau penempatan diperiksa lebih dulu, siswa
 * yang memindai kode MATI dari ruang yang salah diberi tahu bahwa ia salah
 * ruang - padahal yang salah kodenya - lalu ia berjalan ke ruang yang benar
 * hanya untuk ditolak lagi.
 *
 * Sama seperti pintu ruang, yang memeriksa tautannya sebelum kodenya.
 *
 * SATU penyimpangan dari urutan itu, dan alasannya: pengakuan yang SUDAH ada
 * diperiksa sebelum kedaluwarsa. Pindai kedua bukan penolakan melainkan
 * keadaan yang sama (plate B6, catatan keempat) - dan laman "menunggu
 * pengawas" harus tetap terbuka saat dimuat ulang sesudah kodenya mati,
 * karena itu satu-satunya cara siswa melihat apakah pengawasnya sudah
 * mengonfirmasi.
 *
 * =====================================================================
 * Mengapa hash-nya HMAC-SHA256, dan mengapa bukan bcrypt
 * =====================================================================
 * Kode pindai adalah KUNCI PENCARIAN lewat `/hadir/{kode}` - siswa tidak
 * mengirim apa pun selainnya. bcrypt bergaram, sehingga `where('code_hash',
 * $kode)` tidak akan pernah cocok; yang tersisa hanyalah memindai setiap
 * kode aktif dengan `Hash::check` satu per satu, tepat saat tiga ratus siswa
 * memindai berbarengan.
 *
 * Alasan yang sama tertulis di `TokenSesi` dan `ExamToken`.
 */
class KodePindai
{
    /** Umur satu kode, dalam detik - plate A1: "berganti sendiri tiap 60 detik". */
    public const UMUR_DETIK = 60;

    /**
     * Panjang kode, dalam karakter.
     *
     * Enam belas, dan BUKAN enam digit seperti kode pengawas. Perbedaannya
     * bukan selera: kode pengawas diketik manusia di depan pintu, sehingga
     * harus pendek; kode ini tidak pernah diketik siapa pun - ia dibaca
     * kamera dari QR. Yang tersisa hanya tahan-tebakan, dan `/hadir/{kode}`
     * adalah laman publik yang membocorkan nama ruang dan sesi kepada
     * penebaknya.
     *
     * Enam belas karakter alfanumerik berarti 36^16 kemungkinan, dan URL-nya
     * (`/hadir/xxxxxxxxxxxxxxxx`, 23 karakter) masih jauh di bawah batas
     * kepadatan QR yang terbaca ponsel murah.
     */
    public const PANJANG_KODE = 16;

    /**
     * Menit pertama jendela sesi yang masih dihitung TEPAT.
     *
     * Angka ini hanya dipakai untuk MENCENTANG baris di lembar konfirmasi
     * (plate A3) - bukan untuk memutuskan apa pun. Keputusan tetap milik
     * pengawas: baris yang tidak tercentang boleh dicatat sebagai terlambat,
     * dan baris yang tercentang boleh dibatalkan centangnya.
     *
     * Sepuluh menit dipilih karena itu kira-kira waktu yang dibutuhkan
     * antrean masuk satu ruang untuk duduk - bukan angka yang diambil dari
     * dokumen mana pun. Kalau sekolah punya aturan sendiri, yang berubah
     * adalah konstanta ini dan tidak ada tempat lain.
     */
    public const MENIT_TEPAT = 10;

    /**
     * Toleransi sesudah kode mati, untuk tombol konfirmasi.
     *
     * Laman `/hadir/{kode}` dibuka OLEH pindaian, dan tombol "Saya hadir di
     * ruang ini" ditekan beberapa detik kemudian - tetapi kodenya berumur 60
     * detik, sehingga pindai pada detik ke-55 akan menutup tombolnya pada
     * detik ke-57. Siswa itu tidak melakukan kesalahan apa pun.
     *
     * Satu umur kode penuh dipakai sebagai toleransi, bukan angka karangan:
     * artinya kode yang sudah berganti PALING BANYAK SEKALI masih diterima,
     * dan kode dua rotasi lalu tidak. Yang disimpan tetap jam pindainya
     * sendiri, jadi hadir/terlambat tidak terpengaruh.
     *
     * Toleransi ini HANYA dipakai jalur konfirmasi. Pemeriksaan saat laman
     * dibuka memakai nol, supaya kode mati tetap dilaporkan sebagai mati
     * kepada siswa yang baru mengarahkan kameranya.
     */
    public const TOLERANSI_DETIK = self::UMUR_DETIK;

    public function __construct(
        private readonly KehadiranService $kehadiran,
    ) {}

    /* ------------------------------------------------------------- Kode */

    public function hash(string $kode): string
    {
        return hash_hmac('sha256', $kode, (string) config('app.key'));
    }

    /**
     * Terbitkan kode baru. Rotasi berarti BARIS BARU, bukan suntingan.
     *
     * Baris lama dibiarkan mati oleh `expires_at`-nya sendiri. Menyunting
     * satu baris akan menghapus bukti kode mana yang sah ketika seorang
     * siswa ditolak - dan penolakan itulah yang paling perlu bisa
     * dijelaskan ("kode itu sudah 40 detik mati ketika Anda memindainya").
     *
     * =====================================================================
     * Selalu menerbitkan baris baru, tidak pernah memakai ulang yang lama
     * =====================================================================
     * Kodenya TIDAK bisa dibaca kembali dari `code_hash` - itu memang
     * tujuannya. Jadi laman presensi yang menampilkan QR harus memegang kode
     * MENTAHNYA, dan satu-satunya saat ia memegangnya adalah saat ia
     * menerbitkannya. Karena itu setiap muat laman dan setiap habisnya pita
     * waktu menerbitkan satu kode baru.
     *
     * Dua kode sah untuk satu ruang sekaligus bukan masalah: keduanya
     * menunjuk ruang yang sama, dan yang lama mati 60 detik kemudian.
     *
     * @return array{kode: string, baris: AttendanceScanCode}
     */
    public function terbitkan(ExamSessionRoom $ruang): array
    {
        $kode = $this->kodeAcak();
        $sekarang = now();

        $baris = AttendanceScanCode::create([
            'school_id' => $ruang->school_id,
            'exam_session_room_id' => $ruang->id,
            'code_hash' => $this->hash($kode),
            'issued_at' => $sekarang,
            'expires_at' => $sekarang->copy()->addSeconds(self::UMUR_DETIK),
        ]);

        return ['kode' => $kode, 'baris' => $baris];
    }

    private function kodeAcak(): string
    {
        // Tanpa huruf yang mudah tertukar saat dibaca manusia - meski kode
        // ini tidak dibaca manusia. Alfabetnya dijaga pendek supaya QR-nya
        // tidak memuat karakter yang memaksa mode encoding yang lebih padat.
        $alfabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $kode = '';

        for ($i = 0; $i < self::PANJANG_KODE; $i++) {
            $kode .= $alfabet[random_int(0, strlen($alfabet) - 1)];
        }

        return $kode;
    }

    /* ------------------------------------------------------------ Pindai */

    /**
     * Periksa satu pindai. TIDAK menulis apa pun.
     *
     * @param  User  $siswa  Akun yang memindai - BUKAN kode yang menentukan
     *                       siapa yang dicatat; lihat catatan kelas.
     * @param  int  $toleransiDetik  Lihat `TOLERANSI_DETIK`. Nol saat laman
     *                               dibuka, satu umur kode saat tombolnya
     *                               ditekan.
     */
    public function periksa(string $kode, User $siswa, int $toleransiDetik = 0): HasilPindai
    {
        // (1) KODE. Kueri berindeks lewat hash-nya, tersaring tenant oleh
        // SchoolScope - siswa hanya bisa menemukan kode sekolahnya sendiri.
        $baris = AttendanceScanCode::query()
            ->where('code_hash', $this->hash($kode))
            ->first();

        if ($baris === null) {
            return $this->tolak(
                'Kode tidak dikenal',
                'Kode yang Anda pindai bukan kode presensi ruang ujian. Arahkan kamera ke QR '
                .'yang tampil di layar Pengawas ruang Anda, lalu pindai lagi.',
            );
        }

        $ruang = $baris->room;
        $sesi = $ruang?->session;

        if ($ruang === null || $sesi === null) {
            return $this->tolak(
                'Kode tidak berlaku lagi',
                'Ruang untuk kode ini sudah tidak ada. Hubungi Pengawas ruang Anda.',
            );
        }

        // (2) PENEMPATAN. Dicari LEWAT ruangnya, sehingga penempatan di ruang
        // lain tidak pernah ditemukan di sini - dan sebabnya dicari terpisah
        // di bawah supaya kalimatnya bisa menyebut ruang yang benar.
        $peserta = ExamParticipant::query()
            ->where('exam_session_room_id', $ruang->id)
            ->where('student_id', $siswa->id)
            ->where('exam_id', $sesi->exam_id)
            ->first();

        if ($peserta === null) {
            return $this->tolakBukanRuang($siswa, $sesi, $ruang);
        }

        // (2b) PENGAKUAN YANG SUDAH ADA, sebelum kedaluwarsa diperiksa.
        //
        // Satu-satunya penyimpangan dari urutan kode-penempatan-sesi-
        // presensi, dan alasannya ada di catatan kelas: laman "menunggu
        // pengawas" dimuat ulang sesudah kodenya mati, dan ia harus tetap
        // terbuka.
        //
        // Dua keadaannya berbeda: yang masih `menunggu` diterima apa adanya
        // (pindai ganda bukan kesalahan), sedangkan yang sudah `dikonfirmasi`
        // dijawab dengan kalimat yang menenangkan - plate B6, penolakan
        // keempat, yang memang bukan galat.
        $ada = AttendanceAcknowledgement::query()
            ->where('exam_participant_id', $peserta->id)
            ->first();

        if ($ada !== null && $ada->status === StatusPengakuan::Dikonfirmasi) {
            $record = AttendanceRecord::query()
                ->where('exam_participant_id', $peserta->id)
                ->first();

            return $this->sudahTercatat($baris, $peserta, $record);
        }

        if ($ada !== null) {
            // Pengakuan yang masih `menunggu` TIDAK cukup untuk menjawab
            // "belum tercatat": pengawas bisa mencatat siswa dari daftar
            // kursi tanpa pernah menekan konfirmasi pengakuan, dan presensi
            // itulah yang menang. Tanpa pemeriksaan ini siswa membaca
            // "menunggu" selamanya padahal ruangnya sudah mencatatnya hadir
            // - dan ia menunggu gerbang yang sebenarnya sudah terbuka.
            $record = AttendanceRecord::query()
                ->where('exam_participant_id', $peserta->id)
                ->first();

            if ($record !== null && $record->attendance_status !== StatusPresensi::Pending) {
                return $this->sudahTercatat($baris, $peserta, $record);
            }

            return new HasilPindai(
                kode: $baris,
                peserta: $peserta,
                pengakuan: $ada,
                penolakan: null,
            );
        }

        $batas = $baris->expires_at->copy()->addSeconds($toleransiDetik);

        if (now()->gt($batas)) {
            // Kalimatnya menyebut ANGKA yang juga dilihat siswa di layar
            // pengawas - plate A2 menaruh pita waktu di dua tempat justru
            // untuk ini. Tanpa angkanya, penolakan terbaca sebagai sistem
            // yang rusak.
            return $this->tolak(
                'Kode sudah kedaluwarsa',
                'Kode itu berlaku sampai '.$baris->expires_at->format('H:i:s')
                .'; pindai Anda terbaca '.now()->format('H:i:s')
                .'. Pindai lagi kode yang sekarang tampil di layar Pengawas.',
            );
        }

        // (3) STATUS SESI. Setelah penempatan, karena siswa yang salah ruang
        // perlu diberi tahu hal itu lebih dulu - lihat catatan kelas.
        if ($sesi->status !== StatusSesi::Berjalan) {
            return $this->tolak(
                'Sesi belum dibuka',
                'Sesi '.$sesi->label().' berstatus '.mb_strtolower($sesi->status->label())
                .', belum berjalan. Pindai kembali setelah Pengawas membuka sesi.',
            );
        }

        // (4) PRESENSI. Pindai kedua TIDAK menulis baris kedua dan TIDAK
        // mengembalikan status yang sudah diputuskan pengawas menjadi
        // `pending` - plate B6, penolakan keempat.
        $record = AttendanceRecord::query()
            ->where('exam_participant_id', $peserta->id)
            ->first();

        if ($record !== null && $record->attendance_status !== StatusPresensi::Pending) {
            return $this->sudahTercatat($baris, $peserta, $record);
        }

        return new HasilPindai(
            kode: $baris,
            peserta: $peserta,
            pengakuan: null,
            penolakan: null,
        );
    }

    /**
     * Jawaban "sudah tercatat" - plate B6, penolakan keempat.
     *
     * Menenangkan, bukan merah: memindai dua kali karena tombolnya tersentuh
     * dua kali bukan kesalahan siswa, dan laman merah untuk keadaan yang
     * sebenarnya sudah beres akan membuat anak memanggil pengawas untuk
     * masalah yang tidak ada.
     */
    private function sudahTercatat(
        AttendanceScanCode $baris,
        ExamParticipant $peserta,
        ?AttendanceRecord $record,
    ): HasilPindai {
        $status = $record?->attendance_status ?? StatusPresensi::Hadir;
        $kata = mb_strtolower($status->label());
        $jam = $record?->verified_at?->format('H:i');

        return new HasilPindai(
            kode: $baris,
            peserta: $peserta,
            pengakuan: null,
            penolakan: new PenolakanPindai(
                'Sudah tercatat '.$kata,
                'Anda sudah dicatat '.$kata
                .($jam !== null ? ' pukul '.$jam : '')
                .' oleh Pengawas. Tidak ada yang perlu dipindai lagi.',
            ),
            tenang: true,
        );
    }

    /**
     * Catat pengakuan siswa, SESUDAH `periksa()` menerimanya.
     *
     * =====================================================================
     * Waktu pindai diambil dari SEKARANG, bukan dari kode
     * =====================================================================
     * Yang menentukan hadir/terlambat adalah kapan siswa benar-benar
     * memindai, dan `issued_at` adalah kapan pengawas menerbitkan kodenya -
     * selisih beberapa detik di antaranya. Memakai `issued_at` akan membuat
     * seluruh ruang tercatat hadir pada detik yang sama.
     *
     * =====================================================================
     * `firstOrCreate`, dan mengapa bukan `create`
     * =====================================================================
     * Tombol yang tersentuh dua kali, atau dua tab yang sama-sama menekan
     * kirim, tidak boleh gagal dengan galat unique - siswa tidak melakukan
     * kesalahan apa pun. Baris yang sudah ada dikembalikan apa adanya,
     * termasuk `scanned_at` lamanya: pindai kedua bukan pindai pertama.
     */
    public function akui(AttendanceScanCode $baris, ExamParticipant $peserta): AttendanceAcknowledgement
    {
        return AttendanceAcknowledgement::query()->firstOrCreate(
            [
                'school_id' => $peserta->school_id,
                'exam_participant_id' => $peserta->id,
            ],
            [
                'attendance_scan_code_id' => $baris->id,
                // Waktu PINDAI, dan itulah yang kelak menurunkan
                // hadir/terlambat - bukan waktu konfirmasi.
                'scanned_at' => now(),
                'status' => StatusPengakuan::Menunggu,
            ],
        );
    }

    /**
     * Pengakuan siswa pada satu penempatan, bila ada.
     *
     * Dipakai laman `/hadir/{kode}` untuk memilih antara "tunggu pengawas"
     * dan "belum mengaku" tanpa harus memeriksa kode lagi - kode yang sudah
     * mati tidak boleh menutup laman yang sudah terbuka.
     */
    public function pengakuan(ExamParticipant $peserta): ?AttendanceAcknowledgement
    {
        return AttendanceAcknowledgement::query()
            ->where('exam_participant_id', $peserta->id)
            ->first();
    }

    /**
     * Penolakan "bukan ruang Anda", dengan ruang yang BENAR ikut disebut.
     *
     * Kalau siswa ternyata punya penempatan di ruang lain pada sesi yang
     * sama, kalimatnya menyebut ruang itu - karena "hubungi pengawas" bukan
     * instruksi yang bisa dijalankan anak yang baru tahu ia salah pintu.
     */
    private function tolakBukanRuang(User $siswa, object $sesi, ExamSessionRoom $ruang): HasilPindai
    {
        $lain = ExamParticipant::query()
            ->where('exam_session_id', $sesi->id)
            ->where('student_id', $siswa->id)
            ->whereNotNull('exam_session_room_id')
            ->with('room.eventRoom.room:id,code,name')
            ->first();

        $kodeLain = $lain?->room?->eventRoom?->room?->code;
        $kodeIni = $ruang->eventRoom?->room?->code ?? 'ruang ini';

        return $this->tolak(
            'Bukan ruang Anda',
            $kodeLain !== null
                ? "Penempatan Anda di **{$kodeLain}** untuk sesi ini, bukan {$kodeIni}. "
                    .'Kerjakan di ruang yang tertulis di kartu peserta Anda.'
                : "Anda tidak ditempatkan di {$kodeIni} untuk sesi ini. "
                    .'Hubungi Pengawas ruang Anda.',
        );
    }

    private function tolak(string $judul, string $pesan): HasilPindai
    {
        return new HasilPindai(
            kode: null,
            peserta: null,
            pengakuan: null,
            penolakan: new PenolakanPindai($judul, $pesan),
        );
    }

    /* -------------------------------------------------------- Konfirmasi */

    /**
     * Konfirmasi pengawas atas sekelompok pengakuan - plate A3.
     *
     * Satu baris log per siswa, meski satu tombol yang menekannya: itu janji
     * `KehadiranService::verifikasiMassal()`, dan di sini janji yang sama
     * ditepati dengan memanggil `catat()` satu per satu - karena status tiap
     * baris DITURUNKAN dari jam pindainya sendiri, bukan dari satu status
     * yang sama untuk semua.
     *
     * Penyaringan ruang dilakukan lagi di sini, bukan dipercaya dari daftar
     * id yang dikirim peramban - pola yang sama dengan `verifikasiMassal()`.
     *
     * @param  array<int, int>  $pengakuanIds
     * @return array{berubah: int, dilewati: int}
     */
    public function konfirmasi(
        ExamSessionRoom $ruang,
        array $pengakuanIds,
        KonteksRuang $konteks,
    ): array {
        return DB::transaction(function () use ($ruang, $pengakuanIds, $konteks) {
            $baris = AttendanceAcknowledgement::query()
                ->whereIn('id', $pengakuanIds)
                ->whereHas('penempatan', fn ($q) => $q->where('exam_session_room_id', $ruang->id))
                ->where('status', StatusPengakuan::Menunggu)
                ->get();

            $berubah = 0;
            $dilewati = count($pengakuanIds) - $baris->count();

            foreach ($baris as $pengakuan) {
                $record = AttendanceRecord::query()
                    ->where('exam_participant_id', $pengakuan->exam_participant_id)
                    ->first();

                if ($record === null) {
                    $dilewati++;

                    continue;
                }

                // Status dari JAM PINDAI, bukan jam konfirmasi - plate C.
                $status = $this->statusDariPindai($pengakuan);

                $sebelum = $record->attendance_status;

                $this->kehadiran->catat($record, $status, $konteks);

                if ($record->attendance_status !== $sebelum) {
                    $berubah++;
                }

                // Pengakuan tetap ditandai dikonfirmasi meski statusnya
                // kebetulan sudah sama: yang ditandai adalah KEPUTUSAN
                // pengawas atas baris itu, bukan selisih status presensi.
                $pengakuan->forceFill([
                    'status' => StatusPengakuan::Dikonfirmasi,
                    'confirmed_at' => now(),
                ])->save();
            }

            return ['berubah' => $berubah, 'dilewati' => $dilewati];
        });
    }

    /**
     * Hadir atau terlambat, diturunkan dari jam pindai.
     *
     * Pengawas yang baru menekan tombol 20 menit kemudian tidak boleh
     * membuat siswa yang datang tepat waktu tercatat terlambat - dan
     * sebaliknya, pindai pukul 08:10 tidak menjadi tepat hanya karena
     * dikonfirmasi pukul 08:12.
     *
     * Batas "tepat" adalah `MENIT_TEPAT` menit pertama jendela sesi. Yang
     * di luar itu tercatat terlambat, dan itu bukan hukuman: terlambat
     * adalah keadaan yang sah, dan ia tercatat dengan namanya sendiri.
     */
    public function statusDariPindai(AttendanceAcknowledgement $pengakuan): StatusPresensi
    {
        $buka = $pengakuan->penempatan?->session?->access_opens_at;

        if ($buka === null) {
            return StatusPresensi::Hadir;
        }

        $batasTepat = $buka->copy()->addMinutes(self::MENIT_TEPAT);

        return $pengakuan->scanned_at->gt($batasTepat)
            ? StatusPresensi::Terlambat
            : StatusPresensi::Hadir;
    }
}
