<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas aplikasi milik sekolah: nama situs, deskripsi, warna tema, ikon.
 *
 * ---------------------------------------------------------------------
 * Mengapa kolom SENDIRI, bukan kunci di dalam `settings`
 * ---------------------------------------------------------------------
 * `settings` ditulis UTUH oleh laman Pengaturan: controller menerima
 * sebelas kunci `required`, lalu menyimpan `$data` apa adanya. Kunci yang
 * tidak ikut divalidasi terhapus pada penyimpanan berikutnya - sehingga
 * menaruh merek di sana berarti setiap kali Admin menyimpan tab Ujian, ikon
 * dan nama situsnya ikut hilang tanpa satu pun pesan.
 *
 * ---------------------------------------------------------------------
 * Mengapa di tabel `schools`
 * ---------------------------------------------------------------------
 * Yang dipasang ke layar beranda dan ikon tab adalah nama dan logo milik
 * SEKOLAH itu, bukan nama produk. Satu basis data bisa memuat lebih dari
 * satu sekolah (lihat `sekolah:daftarkan`), dan merek yang disimpan global
 * akan menampilkan nama sekolah A di perangkat sekolah B.
 *
 * Nilainya nullable: sekolah yang belum pernah mengisi tab Identitas
 * memakai bawaan dari `config('app.name')` dan berkas ikon statis di
 * `public/` - keadaan yang sama dengan sebelum kolom ini ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->json('branding')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('branding');
        });
    }
};
