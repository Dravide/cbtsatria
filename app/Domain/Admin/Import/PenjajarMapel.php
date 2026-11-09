<?php

namespace App\Domain\Admin\Import;

use App\Support\Impor\PenjajarKolom;

/**
 * Penjajar nama kolom berkas impor MATA PELAJARAN.
 *
 * Seluruh algoritmanya tinggal di `App\Support\Impor\PenjajarKolom`; yang ada
 * di sini hanya DAFTAR KOLOMNYA - kelas ini ada karena daftar itu berbeda dari
 * daftar ruang dan daftar pengguna.
 *
 * =====================================================================
 * Kolomnya sama persis dengan form Tambah mapel
 * =====================================================================
 * Kode, Nama, Kelompok, Tingkat, Status - lima field yang sama yang diketik
 * Admin satu per satu lewat `FormMapel`. Tidak ada kolom di sini yang tidak
 * bisa ia isi lewat layar, dan tidak ada field layar yang tidak bisa ia isi
 * lewat berkas. Perbedaan keduanya berarti dua bentuk data yang sah menurut
 * satu jalur dan tidak menurut jalur lain.
 *
 * =====================================================================
 * Hanya `kode` dan `nama` yang WAJIB
 * =====================================================================
 * `kelompok` dan `tingkat` nullable di skemanya, dan `status` punya bawaan
 * `aktif`. Menuntut kelimanya berarti menolak berkas yang seluruh barisnya
 * benar - dan yang menolak kekurangan isi adalah pemeriksaan PER BARIS, bukan
 * pemeriksaan tingkat berkas yang akan menolak seluruh berkas karena satu
 * baris.
 *
 * =====================================================================
 * Tidak ada kolom `id`, dan itu yang membuat impor ini aman diulang
 * =====================================================================
 * Baris dicocokkan lewat `kode` - yang memang unik per sekolah - bukan lewat
 * id yang tidak pernah dilihat Admin. Akibatnya berkas yang sama boleh
 * diunggah ulang setelah diperbaiki: barisnya memperbarui mapel yang sudah
 * ada, bukan membuat mapel kedua dengan kode yang sama (yang akan ditolak
 * basis data dengan galat yang tidak bisa ditindaklanjuti Admin).
 */
class PenjajarMapel extends PenjajarKolom
{
    /**
     * Nama kanonik kolom, dalam urutan yang dipakai template unduhan.
     *
     * Urutannya menentukan bentuk template, bukan cara pembacaannya -
     * `PenjajarKolom` mencari kolom lewat NAMANYA, sehingga Admin boleh
     * mengurutkan ulang kolomnya sesukanya.
     *
     * @var array<int, string>
     */
    public const KOLOM = ['kode', 'nama', 'kelompok', 'tingkat', 'status'];

    /**
     * Kolom yang HARUS ada di berkasnya - lihat catatan kepala kelas.
     *
     * @var array<int, string>
     */
    public const WAJIB = ['kode', 'nama'];

    /**
     * Sinonim nama kolom, supaya Admin tidak perlu menghafal template.
     *
     * `protected`, bukan `private`: kelas induknyalah yang membacanya, dan
     * konstanta private di sini akan terbaca sebagai "tidak ada sinonim"
     * alih-alih sebagai galat.
     *
     * @var array<string, string>
     */
    protected const SINONIM = [
        'kode_mapel' => 'kode',
        'kodemapel' => 'kode',
        'code' => 'kode',
        'kode_pelajaran' => 'kode',
        'nama_mapel' => 'nama',
        'namamapel' => 'nama',
        'mata_pelajaran' => 'nama',
        'mapel' => 'nama',
        'name' => 'nama',
        'kelompok_mapel' => 'kelompok',
        'grup' => 'kelompok',
        'group' => 'kelompok',
        'category' => 'kelompok',
        'kategori' => 'kelompok',
        'tingkat_kelas' => 'tingkat',
        'grade' => 'tingkat',
        'grade_level' => 'tingkat',
        'jenjang' => 'tingkat',
        'is_active' => 'status',
        'aktif' => 'status',
    ];
}
