<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci penempatan - `exam_event_shuffles.locked_at` + `locked_by`.
 *
 * ---------------------------------------------------------------------
 * Apa yang dijaga, dan mengapa `shuffled_at` tidak cukup
 * ---------------------------------------------------------------------
 * `shuffled_at` sudah berarti "kocokan pernah disebar", dan seluruh laman
 * memperlakukannya sebagai "jangan ubah mode di sini". Tetapi ia TIDAK
 * menahan dua tindakan yang justru paling merusak sesudah daftar ruang
 * dicetak dan ditempel di pintu: `Sebar ulang` dan `Kocok ulang`.
 *
 * Keduanya menulis ulang kursi setiap siswa. Satu tekanan yang tidak sengaja
 * pada hari-H membuat daftar yang sudah tercetak tidak berlaku lagi, dan
 * siswa berdiri di depan ruang yang salah - sementara layarnya terlihat
 * benar. Kunci ini menutup jalur itu sampai seseorang MEMBUKANYA dengan
 * sadar.
 *
 * ---------------------------------------------------------------------
 * Buka kunci itu ada, dan memang harus ada
 * ---------------------------------------------------------------------
 * Penempatan yang terkunci selamanya bukan penjagaan, melainkan jalan buntu:
 * ruang yang rusak di hari-H, siswa yang baru terdaftar, atau kocokan yang
 * ternyata salah susun tetap harus bisa diperbaiki. Yang dibutuhkan adalah
 * SATU tindakan yang terlihat - dan alasannya dicatat ke `activity_logs`
 * oleh pemanggilnya.
 *
 * Keduanya NULL-able dan tidak punya nilai bawaan: penempatan yang belum
 * pernah dikunci memang tidak punya waktu kunci, dan nilai bawaan "-" adalah
 * nilai yang harus diingat-ingat setiap kali dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_event_shuffles', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('shuffled_by');
            $table->unsignedBigInteger('locked_by')->nullable()->after('locked_at');

            // RESTRICT, sama dengan `shuffled_by`: menghapus akun Admin tidak
            // boleh menghapus jejak bahwa penempatan itu pernah dikunci.
            $table->foreign(['school_id', 'locked_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_event_shuffles', function (Blueprint $table) {
            $table->dropForeign(['school_id', 'locked_by']);
            $table->dropColumn(['locked_at', 'locked_by']);
        });
    }
};
