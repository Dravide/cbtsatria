<?php

namespace App\Support\Impor;

/**
 * Pembaca berkas impor - kontraknya, bukan salah satu pembacanya.
 *
 * =====================================================================
 * Yang dibaca hanya teks, dan itu yang membuatnya cukup tanpa pustaka
 * =====================================================================
 * Yang lazim adalah memasang `phpoffice/phpspreadsheet` dan selesai. Yang
 * dipilih di sini bukan yang lazim, dan alasannya bisa diperiksa: yang
 * dibutuhkan hanya SATU arah - membaca kolom teks dari lembar kerja - sementara
 * pustaka itu membawa penulis XLSX, mesin rumus, dan penata gaya yang tidak
 * satu pun dipakai di jalur impor.
 *
 * PhpSpreadsheet memang terpasang di proyek ini, tetapi untuk keperluan LAIN:
 * menulis ekspor di modul Grading. Membacanya di sini berarti menjadikan
 * impor - jalur yang harus tetap bekerja saat ada berkas yang aneh - bergantung
 * pada pustaka terbesar di proyek.
 *
 * Yang menggantikannya sudah ada di mesin ini: `ZipArchive` untuk membuka
 * wadah XLSX dan `SimpleXML` untuk membaca isinya. Keduanya ekstensi bawaan
 * PHP, dan itu berarti tidak ada satu pun `composer install` yang bisa gagal
 * karena paket ini.
 *
 * =====================================================================
 * Nomor baris adalah kunci array, dan itu bukan kerapian
 * =====================================================================
 * Kriteria selesai blueprint menuntut galat dilaporkan "tepat barisnya" -
 * "baris 17 pasangannya kurang". Baris 17 yang mana: baris ke-17 dari atas,
 * atau baris 17 sebagaimana dilihat Guru di Excel?
 *
 * Keduanya bisa berbeda, dan bedanya besar. Guru melihat nomor di tepi kiri
 * Excel; ia membuka berkasnya, mencari angka yang disebut laporan, dan
 * memperbaikinya. Kalau laporan menyebut nomor yang dihitung dari baris
 * PERTAMA YANG TIDAK KOSONG, Guru akan memperbaiki baris yang salah - dan
 * kepercayaannya pada laporan itu hilang pada percobaan kedua.
 *
 * Karena itu kuncinya adalah nomor baris berkasnya sendiri, 1-based: baris 1
 * adalah baris judul kolom, persis seperti yang dihitung Excel dan editor
 * teks. Baris kosong TETAP ADA di dalamnya supaya nomornya tidak bergeser -
 * yang membuangnya adalah penjajar kolom, dan ia membuangnya setelah
 * nomornya dipakai untuk melaporkan galat.
 *
 * =====================================================================
 * Mengapa di App\Support, bukan di dalam satu domain
 * =====================================================================
 * Pembaca berkas tidak tahu apa-apa tentang soal maupun pengguna: ia
 * memindahkan sel lembar kerja menjadi array teks. Impor soal dan impor
 * pengguna memakai empat kelas yang sama di folder ini, sehingga aturan
 * "BOM dibuang", "titik koma dikenali", dan "baris kosong tidak menggeser
 * nomor" hanya punya satu definisi.
 */
interface PembacaBerkas
{
    /**
     * Batas baris yang diproses.
     *
     * Diperiksa SEBELUM berkasnya di-parse, bukan sesudah: berkas 50.000 baris
     * yang ditolak setelah dibaca seluruhnya tetap menghabiskan waktu dan
     * memori server, dan penolakan yang mahal sama tidak bergunanya dengan
     * tidak ada penolakan.
     */
    public const BARIS_MAKS = 2000;

    /** Batas ukuran berkas, dalam byte. Diperiksa sebelum dibuka. */
    public const BYTES_MAKS = 2 * 1024 * 1024;

    /**
     * Apakah pembaca ini menangani ekstensi itu.
     *
     * Dipakai pemanggilnya untuk memilih pembaca, sehingga menambah format
     * baru berarti menambah satu kelas - bukan satu cabang `if` di service
     * yang sudah punya tiga.
     */
    public function dukung(string $ekstensi): bool;

    /**
     * Baca seluruh baris berkas.
     *
     * Kunci array adalah NOMOR BARIS di berkasnya, 1-based - lihat catatan di
     * kepala berkas ini tentang mengapa itu bukan kerapian.
     *
     * Setiap barisnya array berskala indeks kolom (0 = kolom A), berisi teks.
     * Sel bertipe lain - angka, tanggal, rumus - dikembalikan sebagai teksnya,
     * karena tujuan satu-satunya pembaca ini adalah memindahkan apa yang
     * tertulis di lembar kerja ke dalam bentuk yang bisa dibaca penjajar
     * kolom.
     *
     * @return array<int, array<int, string>>
     *
     * @throws BerkasImportTidakTerbaca
     */
    public function baca(string $jalur): array;
}
