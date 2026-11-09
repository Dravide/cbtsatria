<?php

namespace App\Domain\Admin\Import;

use App\Support\Impor\PenjajarKolom;

/**
 * Penjajar nama kolom berkas impor PENGGUNA.
 *
 * Seluruh algoritmanya tinggal di `App\Support\Impor\PenjajarKolom`; yang ada
 * di sini hanya DAFTAR KOLOMNYA - alasan yang sama dengan `PenjajarTemplate`
 * di modul Question, dan kelas ini memang ada karena daftar itu berbeda.
 *
 * =====================================================================
 * Satu template untuk tiga peran
 * =====================================================================
 * Tiga template berarti tiga berkas yang harus dijaga bersama, dan satu
 * pertanyaan yang tidak bisa dijawab Admin: "berkas mana yang saya kirim?"
 * Perannya ditentukan PER BARIS di kolom pertamanya, dan kolom identitas yang
 * tidak berlaku bagi peran itu dibiarkan kosong.
 *
 * Itu pula sebabnya `WAJIB` hanya dua kolom. `nis` tidak wajib bagi guru,
 * `nip` tidak wajib bagi siswa, dan pemeriksaan tingkat berkas yang menuntut
 * ketiganya akan menolak berkas yang seluruh barisnya benar. Yang menolak
 * kekurangan isi adalah pemeriksaan PER BARIS di `ImporPenggunaService`.
 *
 * =====================================================================
 * Tidak ada kolom `username`, dan tidak ada kolom `password`
 * =====================================================================
 * Username diturunkan dari NIS/NIP (`UserRegistrar::usernameDari()`), dan
 * alasannya sudah tertulis di kelas itu: satu-satunya identitas yang diketik
 * siswa ada di kartunya. Kolom yang bisa diisi Admin berarti kolom yang bisa
 * bertabrakan dengan akun sekolah lain - dan kegagalannya muncul di layar
 * login siswa pada hari ujian.
 *
 * `password` sengaja TIDAK ada di template: sandi mentah yang beredar di satu
 * lembar Excel bersama seluruh siswa adalah kebocoran. Sandi awal akun baru
 * adalah `password`, persis jalur buat manual; sandi akun lama tidak pernah
 * disentuh.
 */
class PenjajarPengguna extends PenjajarKolom
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
    public const KOLOM = [
        'peran', 'nama',
        'nis', 'nisn',
        'nip', 'kode_pegawai',
        'kelas',
        'email', 'telepon', 'jenis_kelamin', 'alamat', 'aktif',
    ];

    /**
     * Kolom yang HARUS ada di berkasnya.
     *
     * Hanya dua - lihat catatan kepala kelas.
     *
     * @var array<int, string>
     */
    public const WAJIB = ['peran', 'nama'];

    /**
     * Judul kolom ekspor: kolom template + `username`.
     *
     * `username` TIDAK ada di `KOLOM` - ia diturunkan dari nomor identitas dan
     * tidak boleh diketik Admin. Ia ada di ekspor karena tanpanya berkas
     * hasilnya tidak bisa dipakai untuk menjawab pertanyaan yang paling sering
     * ditanyakan pada hari ujian: "username saya apa?"
     *
     * Kolomnya tidak mengganggu impor. `PenjajarKolom` mencari kolom lewat
     * NAMANYA, dan judul yang tidak dikenal diabaikan - sehingga berkas ekspor
     * bisa langsung diunggah kembali, dan itulah yang membuat mode "perbarui
     * akun lama" punya nilai ujung-ke-ujung: ekspor, perbaiki di Excel, unggah
     * ulang; barisnya cocok lewat NIS, username dan sandi tak tersentuh.
     *
     * @return array<int, string>
     */
    public static function judulEkspor(): array
    {
        return array_merge(['username'], static::KOLOM);
    }

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
        'role' => 'peran',
        'jabatan' => 'peran',
        'nama_lengkap' => 'nama',
        'nama_siswa' => 'nama',
        'nama_guru' => 'nama',
        'name' => 'nama',
        'no_induk' => 'nis',
        'nomor_induk' => 'nis',
        'induk' => 'nis',
        'nis_nasional' => 'nisn',
        'nip_guru' => 'nip',
        'staff_code' => 'kode_pegawai',
        'pegawai' => 'kode_pegawai',
        'kelas_rombel' => 'kelas',
        'rombel' => 'kelas',
        'class' => 'kelas',
        'surel' => 'email',
        'e_mail' => 'email',
        'mail' => 'email',
        'telepon' => 'telepon',
        'no_hp' => 'telepon',
        'nohp' => 'telepon',
        'hp' => 'telepon',
        'phone' => 'telepon',
        'jeniskelamin' => 'jenis_kelamin',
        'gender' => 'jenis_kelamin',
        'kelamin' => 'jenis_kelamin',
        'alamat_lengkap' => 'alamat',
        'address' => 'alamat',
        'is_active' => 'aktif',
        'status' => 'aktif',
    ];
}
