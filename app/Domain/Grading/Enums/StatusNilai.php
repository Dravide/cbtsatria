<?php

namespace App\Domain\Grading\Enums;

/**
 * Keadaan penilaian satu hasil - PRD §13.4.
 *
 * ---------------------------------------------------------------------
 * Empat nilai, dan yang keempat BUKAN tangga yang lebih tinggi
 * ---------------------------------------------------------------------
 * PRD menulisnya sebagai rantai: Not Graded -> Partially Graded ->
 * Fully Graded -> Published. Terlihat seperti kemajuan satu arah, dan pada
 * kenyataannya bukan: tiga yang pertama adalah keadaan PENILAIAN, yang
 * keempat adalah keadaan PUBLIKASI.
 *
 * Bedanya terlihat pada satu kejadian yang pasti terjadi: Admin membuka
 * kembali kunci koreksi karena satu poin salah (blueprint keputusan #10).
 * Hasilnya kembali ke `dinilai_sebagian`, sementara `published_at` di baris
 * yang sama TETAP terisi - siswa tidak kehilangan nilainya karena Guru perlu
 * memperbaiki satu poin.
 *
 * Karena itu `terbit` TIDAK dipakai sebagai keadaan tersimpan setelah
 * publikasi. Ia dikirim ke layar sebagai keadaan TURUNAN: `published_at`
 * terisi berarti terbit, apa pun keadaan penilaiannya. Enum ini tetap
 * memuatnya supaya kosakata PRD utuh, dan `dari()` yang memutuskan.
 *
 * ---------------------------------------------------------------------
 * Nilai bawaannya `belum_dinilai`, bukan `dinilai_penuh`
 * ---------------------------------------------------------------------
 * Hasil yang lahir tanpa satu pun uraian memang langsung `dinilai_penuh` -
 * tidak ada yang menunggu. Tetapi bawaannya tetap `belum_dinilai`, karena
 * baris yang lahir karena kelalaian tidak boleh terbaca sebagai selesai.
 */
enum StatusNilai: string
{
    case BelumDinilai = 'belum_dinilai';
    case DinilaiSebagian = 'dinilai_sebagian';
    case DinilaiPenuh = 'dinilai_penuh';
    case Terbit = 'terbit';

    public function label(): string
    {
        return match ($this) {
            self::BelumDinilai => 'Belum dinilai',
            self::DinilaiSebagian => 'Dinilai sebagian',
            self::DinilaiPenuh => 'Dinilai penuh',
            self::Terbit => 'Terbit',
        };
    }

    public function nada(): string
    {
        return match ($this) {
            self::Terbit => 'ok',
            self::DinilaiPenuh => 'info',
            self::DinilaiSebagian => 'warn',
            self::BelumDinilai => 'mute',
        };
    }

    /**
     * Apakah nilainya boleh dipakai sebagai nilai akhir.
     *
     * Hanya `dinilai_penuh` dan `terbit`. Ini yang menegakkan kalimat PRD
     * §13.4 - "hasil yang masih mempunyai essay belum terkoreksi tidak boleh
     * dianggap final" - sebagai satu method, bukan sebagai pemeriksaan yang
     * ditulis ulang di setiap laporan.
     */
    public function final(): bool
    {
        return $this === self::DinilaiPenuh || $this === self::Terbit;
    }

    /**
     * Keadaan yang benar dari tiga fakta yang tersimpan.
     *
     * Dipakai `PenghitungHasil` dan `PublikasiHasil`, sehingga tidak ada dua
     * tempat yang menyimpulkan keadaan ini dengan aturan yang berbeda.
     *
     * `terbit` MENANG atas ketiga keadaan lainnya. Publikasi tidak dibatalkan
     * saat koreksi dibuka kembali, jadi hasil yang terbit tetap terbit meski
     * beberapa uraiannya sedang dikoreksi ulang - dan `sisaUraian`-nya yang
     * memberi tahu bahwa angkanya belum final.
     */
    public static function dari(int $sisaUraian, int $totalUraian, bool $terbit): self
    {
        if ($terbit) {
            return self::Terbit;
        }

        if ($totalUraian === 0) {
            // Ujian tanpa uraian selesai seketika - tidak ada yang menunggu.
            return self::DinilaiPenuh;
        }

        if ($sisaUraian === 0) {
            return self::DinilaiPenuh;
        }

        if ($sisaUraian >= $totalUraian) {
            return self::BelumDinilai;
        }

        return self::DinilaiSebagian;
    }
}
