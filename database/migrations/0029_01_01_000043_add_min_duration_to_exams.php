<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas waktu MINIMAL pengerjaan - `exams.min_duration_minutes`.
 *
 * ---------------------------------------------------------------------
 * Apa yang dicegah, dan mengapa toleransi tidak cukup
 * ---------------------------------------------------------------------
 * Ujian 120 menit tanpa batas minimal berarti siswa dapat menekan Kumpulkan
 * pada menit kelima - karena soalnya mudah, karena ia ingin pulang, atau
 * karena ia melihat temannya sudah keluar. Nilainya tetap sah, dan itu yang
 * membuatnya berbahaya: tidak ada satu pun pemeriksaan yang bisa membedakan
 * "selesai cepat" dari "menyerah lebih dulu".
 *
 * `NULL` berarti tidak ada batas minimal, dan itu nilai bawaan untuk seluruh
 * ujian yang sudah ada: mengubahnya menjadi angka akan mengubah arti jadwal
 * yang sudah dicetak dan berita acara yang sudah ditandatangani.
 *
 * ---------------------------------------------------------------------
 * Mengapa di `exams`, bukan di `exam_sessions`
 * ---------------------------------------------------------------------
 * Ia sifat pengerjaannya, bukan sifat jendela waktunya. Dua sesi ujian yang
 * sama (utama dan susulan) menuntut aturan minimal yang SAMA - dan
 * menyalinnya ke sesi berarti dua tempat yang bisa berbeda, tepat pada ujian
 * yang susulannya disusun belakangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedInteger('min_duration_minutes')->nullable()->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('min_duration_minutes');
        });
    }
};
