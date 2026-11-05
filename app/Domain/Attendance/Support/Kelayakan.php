<?php

namespace App\Domain\Attendance\Support;

/**
 * Hasil pemeriksaan kelayakan seorang peserta untuk memulai/melanjutkan ujian.
 *
 * ---------------------------------------------------------------------
 * Mengapa objek, bukan boolean
 * ---------------------------------------------------------------------
 * PRD §10.8 mencantumkan SEBELAS syarat, dan setiap syarat yang gagal
 * menghasilkan kalimat yang berbeda kepada siswa. Boolean akan memaksa
 * pemanggilnya mengarang kalimatnya sendiri - dan dua tempat yang mengarang
 * kalimat untuk penolakan yang sama akan berbeda kata, lalu berbeda arti.
 *
 * Plate 20 menuntut satu bentuk tertentu untuk penolakan presensi, dan
 * kalimatnya disalin apa adanya dari PRD §10.9. Objek ini membawa kalimat
 * itu, bukan kode galat.
 *
 * ---------------------------------------------------------------------
 * `boleh` dan `alasan` tidak pernah keduanya terisi
 * ---------------------------------------------------------------------
 * Undangan yang selalu menjelaskan akan membuat pemanggil mengabaikan
 * keduanya; undangan yang tidak pernah keduanya terisi berarti tidak ada
 * yang diperiksa. Kedua konstruktor statis di bawah adalah satu-satunya
 * jalan membuatnya, dan keduanya menjaga invarian itu.
 */
final class Kelayakan
{
    private function __construct(
        public readonly bool $boleh,
        public readonly ?string $alasan,
        /** Langkah berikutnya yang bisa dilakukan siswa, bila ada. */
        public readonly ?string $petunjuk,
    ) {}

    public static function boleh(): self
    {
        return new self(true, null, null);
    }

    public static function tolak(string $alasan, ?string $petunjuk = null): self
    {
        return new self(false, $alasan, $petunjuk);
    }

    /** Kalimat PRD §10.9, disalin apa adanya - plate 20 memakainya persis. */
    public const PESAN_BELUM_DIPRESENSI =
        'Anda belum mendapatkan verifikasi kehadiran dari Pengawas ruang ujian. '.
        'Silakan menghubungi Pengawas yang bertugas untuk melakukan verifikasi sebelum memulai ujian.';
}
