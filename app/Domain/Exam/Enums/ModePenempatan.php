<?php

namespace App\Domain\Exam\Enums;

/**
 * Cara peserta dibagi ke ruang - PRD §9.5, wireframe plate 16b.
 *
 * =====================================================================
 * Dua sekolah, dua kebiasaan, satu laman
 * =====================================================================
 * Sebagian sekolah menuntut ujian CAMPURAN: satu ruang berisi beberapa
 * kelas, supaya anak tidak bisa bekerja sama dengan teman sekelasnya.
 * Sebagian lain menuntut yang sebaliknya: satu ruang satu kelas, karena
 * pengawasnya guru kelas itu, daftar absennya satu lembar, dan
 * pengumumannya satu kalimat per kelas.
 *
 * Sampai sekarang aplikasi ini hanya mengenal yang pertama, dan sekolah
 * jenis kedua tidak punya jalan sama sekali - yang mereka kerjakan adalah
 * menimpa kursi satu per satu di luar aplikasi.
 *
 * =====================================================================
 * Mode disimpan bersama benihnya, bukan dipilih ulang setiap kali
 * =====================================================================
 * Denah, tablo, dan daftar presensi menghitung ulang peta dari benih
 * tersimpan. Tanpa mode tersimpan, tiga laman itu akan memakai mode
 * bawaan - dan susunan yang tercetak di pintu berbeda dari yang di layar,
 * tepat pada jenis kesalahan yang paling mahal di aplikasi ini.
 */
enum ModePenempatan: string
{
    /** Rotasi antar kelas - satu ruang berisi beberapa kelas. Bawaan. */
    case Campur = 'campur';

    /** Kelas berurutan ke ruang berurutan - satu ruang sedapat mungkin satu kelas. */
    case Kelas = 'kelas';

    public function label(): string
    {
        return match ($this) {
            self::Campur => 'Campur antar kelas',
            self::Kelas => 'Berurutan per kelas',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $pilihan = [];

        foreach (self::cases() as $case) {
            $pilihan[$case->value] = $case->label();
        }

        return $pilihan;
    }
}
