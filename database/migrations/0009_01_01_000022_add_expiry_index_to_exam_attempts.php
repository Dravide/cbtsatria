<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk pemangkas attempt kedaluwarsa - Fase 9.
 *
 * =====================================================================
 * Mengapa `(status, deadline_at)`, dan bukan `(school_id, status)`
 * =====================================================================
 * Perintah `ujian:tutup-kedaluwarsa` menanyakan satu hal setiap menit:
 *
 *     status = 'berjalan' AND deadline_at <= sekarang()
 *
 * Dan ia menanyakannya **lintas sekolah**, karena penjadwal berjalan tanpa
 * pengguna sehingga tidak ada konteks tenant yang menyaringnya. Indeks yang
 * sudah ada - `(school_id, status)` - tidak bisa dipakai untuk kueri itu:
 * kolom pertamanya justru yang TIDAK dibatasi, dan MySQL memerlukan kolom
 * awalan indeks untuk bisa memakai sisanya.
 *
 * Akibatnya tanpa indeks ini kueri tersebut adalah pemindaian tabel penuh
 * SETIAP MENIT terhadap tabel yang tumbuh sepanjang ujian berlangsung -
 * tepat pada tabel yang paling sibuk, dan tepat pada saat papan monitoring
 * membacanya paling sering.
 *
 * `deadline_at` di urutan kedua, bukan `status` saja: statusnya hanya
 * memiliki lima nilai berbeda, sehingga indeks satu kolom di sana akan
 * menyisakan hampir seluruh tabel untuk disaring dengan perbandingan waktu.
 *
 * =====================================================================
 * Urutannya `(status, deadline_at)`, bukan sebaliknya
 * =====================================================================
 * `status` mendahului karena ia yang paling menyempitkan: `berjalan` adalah
 * sebagian kecil baris pada sekolah yang ujiannya sudah selesai. Di dalam
 * status itu, `deadline_at` yang diurutkan membuat `<= now()` menjadi rentang
 * yang bisa diambil langsung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            // Nama eksplisit supaya `down()` dan kueri penjelasnya tidak
            // bergantung pada nama yang disusun Laravel dari daftar kolom -
            // nama yang berubah begitu urutan kolomnya disunting.
            $table->index(['status', 'deadline_at'], 'attempt_kedaluwarsa_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('attempt_kedaluwarsa_idx');
        });
    }
};
