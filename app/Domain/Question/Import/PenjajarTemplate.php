<?php

namespace App\Domain\Question\Import;

use App\Support\Impor\PenjajarKolom;

/**
 * Penjajar nama kolom berkas impor SOAL.
 *
 * Seluruh algoritmanya - nama kolom dicari alih-alih dihitung urutannya,
 * BOM dibuang, sinonim diterjemahkan, kolom yang tidak ada mengembalikan
 * string kosong, pesan galat menyebut kolom yang terbaca - tinggal di
 * `App\Support\Impor\PenjajarKolom`. Yang ada di sini hanya DAFTAR KOLOMNYA.
 *
 * ---------------------------------------------------------------------
 * Mengapa dipisah begitu
 * ---------------------------------------------------------------------
 * Impor pengguna membutuhkan aturan yang sama persis dengan kolom yang
 * berbeda. Menyalin algoritmanya ke kelas kedua berarti dua tempat yang bisa
 * berbeda diam-diam pada hal yang paling menentukan - kolom mana yang
 * benar-benar dibaca importer - dan itu justru kelas bug yang
 * DomainBoundariesTest ada untuk mencegah.
 *
 * Konstanta di bawah dibaca kelas induknya lewat `static::`, sehingga kelas
 * ini tetap hanya berisi daftar, dan pemanggilnya tidak berubah sama sekali:
 * `PenjajarTemplate::judul()` dan `new PenjajarTemplate($sel, $no)` bekerja
 * persis seperti sebelumnya.
 */
class PenjajarTemplate extends PenjajarKolom
{
    /**
     * Nama kanonik kolom, dalam urutan yang dipakai template unduhan.
     *
     * Urutannya menentukan bentuk template dan pesan galat "kolom wajib belum
     * ada", bukan cara pembacaannya - lihat catatan di kelas induknya.
     *
     * @var array<int, string>
     */
    public const KOLOM = [
        'kode', 'judul', 'jenis', 'tingkat', 'bobot',
        'soal',
        'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e',
        'kunci', 'rubrik', 'pembahasan',
    ];

    /**
     * Kolom yang HARUS ada di berkasnya.
     *
     * Hanya empat, dan daftarnya sengaja pendek: `kode` boleh kosong (service
     * membangkitkannya), kolom opsi boleh kosong untuk jenis yang tidak
     * memakainya, dan rubrik hanya untuk uraian. Yang menolak kekurangan isi
     * adalah SkemaJenisSoal, per baris - bukan pemeriksaan tingkat berkas yang
     * akan menolak seluruh berkas karena satu baris.
     *
     * @var array<int, string>
     */
    public const WAJIB = ['judul', 'jenis', 'soal'];

    /**
     * Sinonim nama kolom, supaya Guru tidak perlu menghafal template.
     *
     * "jawaban" untuk kunci, "tipe"/"tipe soal" untuk jenis, "pertanyaan" untuk
     * soal. Ketiganya adalah nama yang dipilih separuh orang, dan menolaknya
     * berarti mengajari Guru sebuah kosakata yang tidak ia pakai.
     *
     * `protected`, bukan `private`: kelas induknyalah yang membacanya, dan
     * konstanta private di sini akan terbaca sebagai "tidak ada sinonim"
     * alih-alih sebagai galat.
     *
     * @var array<string, string>
     */
    protected const SINONIM = [
        'jawaban' => 'kunci',
        'kunci_jawaban' => 'kunci',
        'jawaban_benar' => 'kunci',
        'tipe' => 'jenis',
        'tipe_soal' => 'jenis',
        'jenis_soal' => 'jenis',
        'pertanyaan' => 'soal',
        'teks_soal' => 'soal',
        'isi_soal' => 'soal',
        'nama' => 'judul',
        'tingkat_kesulitan' => 'tingkat',
        'kesulitan' => 'tingkat',
        'skor' => 'bobot',
        'poin' => 'bobot',
        'pembahasan_soal' => 'pembahasan',
        'penjelasan' => 'pembahasan',
        'a' => 'opsi_a', 'b' => 'opsi_b', 'c' => 'opsi_c',
        'd' => 'opsi_d', 'e' => 'opsi_e',
    ];
}
