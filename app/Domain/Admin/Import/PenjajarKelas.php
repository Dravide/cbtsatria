<?php

namespace App\Domain\Admin\Import;

use App\Support\Impor\PenjajarKolom;

/**
 * Penjajar nama kolom berkas impor KELAS.
 *
 * Seluruh algoritmanya tinggal di `App\Support\Impor\PenjajarKolom`; yang ada
 * di sini hanya DAFTAR KOLOMNYA.
 *
 * =====================================================================
 * `nama` adalah kolom KUNCI di sini, dan `kode` di dua importer lain
 * =====================================================================
 * Tabel `classes` tidak punya kolom `code` sama sekali - lihat
 * `0002_01_01_000006_create_academic_tables.php`. Yang unik di sana adalah
 * `['school_id','academic_year_id','name']`, dan nama kelas itu sendiri yang
 * dipakai orang menyebutnya: "X-1", "XI TKJ 2".
 *
 * Menambahkan kolom `kode` hanya supaya importer ketiga ini mirip dua yang lain
 * berarti menuntut Admin mengarang kode untuk setiap kelas - pekerjaan yang
 * tidak diminta laman mana pun, untuk kunci yang tetap saja tidak muncul di
 * layar Kelas. Yang dicocokkan di sini adalah hal yang sama yang dicocokkan
 * `SchoolClassController::update()`: namanya.
 *
 * Karena itu pula `WAJIB` di sini adalah `nama`, bukan `kode` - dan itulah
 * satu-satunya perbedaan bentuk antara berkas ini dan berkas mapel.
 *
 * =====================================================================
 * Tahun ajaran TIDAK menjadi kolom, dan alasannya bukan kerapian
 * =====================================================================
 * Nama kelas unik PER TAHUN AJARAN: "X-1" muncul lagi setiap tahun dan itu
 * bukan duplikat. Berkas kelas karena itu selalu butuh satu tahun ajaran -
 * pertanyaannya hanya dari mana ia datang.
 *
 * Jawabannya dari LAMAN, bukan dari berkasnya. Wireframe plate 07:
 *
 *   "Berganti TA berarti berganti daftar kelas, bukan menyaring daftar yang
 *    sama."
 *
 * Laman Kelas sudah memilih satu tahun ajaran di bar atasnya, dan Admin yang
 * menekan Impor di sana sedang melihat daftar tahun itu. Berkas yang membawa
 * kolom `tahun` sendiri berarti dua tempat memilih tahun yang sama, dan berkas
 * yang lupa mengisinya tidak punya jawaban yang benar: menebak tahun aktif
 * berarti menulis kelas ke tahun ajaran yang salah, diam-diam, untuk seluruh
 * berkasnya sekaligus. Yang dipilih di layar terlihat di layar.
 *
 * =====================================================================
 * `wali` sengaja TIDAK ada sebagai kolom
 * =====================================================================
 * Alasan yang sama dengan impor mapel yang tidak menyentuh penugasan mengajar
 * (lihat kepala `ImporMasterService`): wali kelas adalah HUBUNGAN antara satu
 * kelas dan satu akun guru, bukan atribut yang berdiri sendiri.
 *
 * Menyambungkannya di importer ini menuntut pencocokan guru lewat nama atau
 * NIP, dan `teacher_profiles.nip` TIDAK unik - `PencariAkun` mencatat akibatnya
 * panjang: `where('nip', …)->first()` bisa MEMPERBARUI ORANG YANG SALAH, dan
 * kerusakannya baru muncul di layar login orang lain berhari-hari kemudian.
 * Baris yang salah walinya bukan baris yang bisa diperbaiki Admin berikutnya
 * dengan mudah; ia sudah menjadi data yang dipakai laporan.
 *
 * Wali tetap dipilih satu per satu di laman Kelas, tempat Admin melihat akun
 * yang ia pilih dan kelas yang ia wakilkan.
 */
class PenjajarKelas extends PenjajarKolom
{
    /**
     * Nama kanonik kolom, dalam urutan yang dipakai template unduhan.
     *
     * @var array<int, string>
     */
    public const KOLOM = ['nama', 'tingkat', 'status'];

    /**
     * Kolom yang HARUS ada di berkasnya - `nama`, lihat catatan kepala kelas.
     *
     * @var array<int, string>
     */
    public const WAJIB = ['nama'];

    /**
     * Sinonim nama kolom, supaya Admin tidak perlu menghafal template.
     *
     * `name` dan `kelas` ada di daftar ini karena dua-duanya nama yang lazim
     * dipakai orang untuk kolom yang sama - dan berkas yang menyebut "Kelas"
     * sebagai judul kolomnya adalah berkas yang isinya benar.
     *
     * @var array<string, string>
     */
    protected const SINONIM = [
        'nama_kelas' => 'nama',
        'namakelas' => 'nama',
        'kelas' => 'nama',
        'class' => 'nama',
        'rombel' => 'nama',
        'rombongan_belajar' => 'nama',
        'name' => 'nama',
        'tingkat_kelas' => 'tingkat',
        'jenjang' => 'tingkat',
        'grade' => 'tingkat',
        'grade_level' => 'tingkat',
        'level' => 'tingkat',
        'is_active' => 'status',
        'aktif' => 'status',
    ];
}
