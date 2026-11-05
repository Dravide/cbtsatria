<?php

namespace App\Support\Impor;

use RuntimeException;

/**
 * Berkas impor tidak bisa dibaca.
 *
 * ---------------------------------------------------------------------
 * Mengapa kelas sendiri, bukan RuntimeException biasa
 * ---------------------------------------------------------------------
 * Pesan galat berkas impor dibaca GURU, bukan developer. Ia harus menyebut
 * apa yang salah DENGAN BERKASNYA dan apa yang harus dilakukan - "berkas .xls
 * lama tidak didukung, simpan ulang sebagai .xlsx atau .csv" - dan pesan itu
 * tidak boleh tertukar dengan galat tak terduga lain yang juga melempar
 * RuntimeException di jalur yang sama.
 *
 * Dengan kelas sendiri, penangkapnya bisa membedakan "berkasmu bermasalah"
 * dari "ada yang rusak di server", dan hanya yang pertama ditampilkan di layar
 * Guru. Yang kedua masuk log, karena Guru tidak bisa berbuat apa pun dengannya.
 *
 * ---------------------------------------------------------------------
 * Mengapa di App\Support, bukan di dalam satu domain
 * ---------------------------------------------------------------------
 * Kelas ini dipakai impor soal DAN impor pengguna. Ia tidak berbicara tentang
 * soal, dan tidak tentang pengguna - yang ia bicarakan adalah berkas. Kelas
 * yang tinggal di dalam salah satu domain memaksa domain lain mengimpornya,
 * dan itulah yang membuatnya pindah ke sini.
 */
class BerkasImportTidakTerbaca extends RuntimeException
{
    public static function kosong(): self
    {
        return new self('Berkasnya kosong. Isi dulu baris datanya, lalu unggah ulang.');
    }

    public static function terlaluBesar(int $byte): self
    {
        $mb = round($byte / 1024 / 1024, 1);

        return new self(
            'Berkasnya '.$mb.' MB, sedangkan batasnya '.
            round(PembacaBerkas::BYTES_MAKS / 1024 / 1024).' MB. '.
            'Pecah menjadi beberapa berkas, lalu impor satu per satu.',
        );
    }

    public static function terlaluBanyakBaris(int $baris): self
    {
        return new self(
            'Berkasnya berisi '.number_format($baris, 0, ',', '.').' baris, sedangkan batasnya '.
            number_format(PembacaBerkas::BARIS_MAKS, 0, ',', '.').' baris per sekali impor. '.
            'Pecah menjadi beberapa berkas, lalu impor satu per satu.',
        );
    }

    public static function bukanZip(): self
    {
        return new self(
            'Berkas ini bukan .xlsx yang sah. Kalau ini berkas .xls lama (Excel 97-2003), '.
            'buka di Excel lalu simpan ulang sebagai .xlsx atau .csv - format lama itu bukan '.
            'arsip zip, sehingga isinya tidak bisa dibaca di sini.',
        );
    }

    public static function tanpaLembar(): self
    {
        return new self('Berkasnya tidak berisi lembar kerja. Buka di Excel dan pastikan ada tab sheet yang terisi.');
    }

    public static function csvTanpaPemisah(string $contoh): self
    {
        return new self(
            'Baris pertama berkas tidak memuat pemisah kolom. Yang terbaca: "'.$contoh.'". '.
            'Unduh template impor, lalu isi mengikuti bentuknya.',
        );
    }
}
