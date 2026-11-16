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

    /**
     * Acak rata per kelas - tiap kelas disebar MERATA ke seluruh ruang.
     *
     * Bedanya dengan `Campur` bukan pada pencampurannya, melainkan pada
     * KAPAN ruang dianggap penuh. `Campur` mengisi ruang pertama sampai penuh
     * lalu pindah ke berikutnya, sehingga ruang terakhir bisa tinggal separuh.
     * `Rata` membagi tiap kelas ke semua ruang sekaligus, sehingga setiap
     * ruang berakhir hampir sama besar - dan itu yang diminta sekolah yang
     * membagi pengawas berdasarkan jumlah kursi terpakai, bukan jumlah ruang.
     */
    case Rata = 'rata';

    public function label(): string
    {
        return match ($this) {
            self::Campur => 'Campur antar kelas',
            self::Kelas => 'Berurutan per kelas',
            self::Rata => 'Acak rata per kelas',
        };
    }

    /**
     * Keterangan satu kalimat yang ditampilkan di samping pilihannya.
     *
     * Ditulis di sini, bukan di `Penempatan.tsx`: kalimat yang menjelaskan
     * sebuah mode adalah bagian dari arti mode itu, dan dua tempat yang
     * menjelaskannya akan berbeda kata begitu salah satunya disunting.
     */
    public function keterangan(): string
    {
        return match ($this) {
            self::Campur => 'Satu dari tiap kelas bergiliran, lalu ruang diisi penuh berurutan.',
            self::Kelas => 'Kelas berurutan ke ruang berurutan - kelas terbesar mendapat ruang pertama.',
            self::Rata => 'Tiap kelas disebar merata ke semua ruang, sehingga semua ruang hampir sama besar.',
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

    /**
     * Keterangan tiap mode, untuk dibaca di samping pilihannya.
     *
     * Dikirim server, bukan ditulis ulang di `Penempatan.tsx`: kalimat yang
     * menjelaskan sebuah mode adalah bagian dari arti mode itu, dan dua tempat
     * yang menjelaskannya akan berbeda kata begitu salah satunya disunting -
     * persis jebakan yang sudah dicatat di `options()`.
     *
     * @return array<string, string>
     */
    public static function keteranganSemua(): array
    {
        $peta = [];

        foreach (self::cases() as $case) {
            $peta[$case->value] = $case->keterangan();
        }

        return $peta;
    }
}
