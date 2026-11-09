<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `question_media.question_id` menjadi boleh kosong - media "draf" sebelum
 * soalnya punya id.
 *
 * =====================================================================
 * Mengapa media harus bisa lahir sebelum soalnya
 * =====================================================================
 * Gambar pada soal dan pilihan jawaban diunggah dari dalam builder. Pada soal
 * BARU, Guru menempel gambar sebelum ia menekan Simpan - jadi belum ada baris
 * `questions` untuk menggantungkan berkasnya. Dua jalan keluarnya:
 *
 *   1. Buat soal kosong lebih dulu ("autosave draf"), lalu unggah ke
 *      soal itu. Ditolak: satu soal kosong tanpa teks menabrak aturan
 *      `prompt_json.teks` wajib DAN mengotori bank soal dengan baris yang
 *      belum tentu pernah diselesaikan.
 *   2. Izinkan media tanpa soal, lalu TAUTKAN saat soal disimpan. Itulah
 *      yang dipilih di sini.
 *
 * ---------------------------------------------------------------------
 * Mengapa kolomnya, bukan tabel kedua
 * ---------------------------------------------------------------------
 * `question_media` sudah menjadi satu-satunya rumah berkas soal, dan tabel
 * "media_draf" kedua berarti dua jalur baca, dua jalur hapus, dan satu
 * migrasi penggabungan yang harus dijalankan cepat atau lambat. Kolom yang
 * boleh kosong jauh lebih kecil daripada tabel baru.
 *
 * ---------------------------------------------------------------------
 * Composite FK tetap sah saat NULL, dan itu bukan kelonggaran
 * ---------------------------------------------------------------------
 * Foreign key `(school_id, question_id) -> questions(school_id, id)` memakai
 * MATCH SIMPLE: bila salah satu kolomnya NULL, MySQL MELEWATI pemeriksaannya.
 * Pola yang sama sudah dipakai `question_media.option_id` dan
 * `exam_tokens.created_by`. Artinya baris draf (question_id NULL) sah, dan
 * `school_id` tetap wajib - sehingga berkas draf tetap terikat sekolahnya,
 * dan pembersih draf bisa menyaring per sekolah.
 *
 * Berkas draf yang ditinggalkan (peramban ditutup sebelum Simpan) dibersihkan
 * `PangkasMediaDraf` - lihat command itu untuk batas waktunya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_media', function (Blueprint $table) {
            // `change()` di Laravel 11+ menulis ulang definisi kolom tanpa
            // doctrine/dbal. Indeks dan FK yang menyebut kolom ini tetap
            // utuh; yang berubah hanya atribut NOT NULL-nya.
            $table->unsignedBigInteger('question_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Mengembalikan NOT NULL hanya aman bila tidak ada baris draf yang
        // tersisa. Pemanggil `down()` yang masih punya draf akan menerima
        // galat basis data - dan itu benar: membuang gambar yang mungkin
        // sedang dipakai soal jauh lebih buruk daripada migrasi yang berhenti.
        Schema::table('question_media', function (Blueprint $table) {
            $table->unsignedBigInteger('question_id')->nullable(false)->change();
        });
    }
};
