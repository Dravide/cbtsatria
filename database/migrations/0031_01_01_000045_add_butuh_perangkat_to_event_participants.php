<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda "peserta ini memakai perangkat sekolah" - wireframe-ruang-lab.html L2.
 *
 * =====================================================================
 * Mengapa di `event_participants`, bukan di `users`
 * =====================================================================
 * "Tidak punya HP" bukan sifat orang, melainkan keadaan yang berlaku untuk
 * satu event. Anak yang HP-nya rusak minggu ini tidak boleh tercatat "tanpa
 * perangkat" sepanjang tahun, dan anak yang tahun lalu meminjam bisa
 * membawa sendiri tahun ini. Dasar keputusannya ada di tiap baris
 * penempatan, bukan di profil.
 *
 * =====================================================================
 * Bawaan `false`, dan itu bukan pilihan sepele
 * =====================================================================
 * Tanda ini MEMAKSA penempatan mencarikan kursi ber-PC. Bawaannya `true`
 * akan membuat setiap event baru menuntut PC untuk seluruh pesertanya, dan
 * sekolah tanpa lab mendapati penempatannya ditolak tanpa pernah menyalakan
 * satu tombol pun. Yang dipilih: Admin menandai yang butuh, bukan
 * membatalkan tanda pada yang tidak butuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            // Setelah `class_id`, di samping kolom peserta lain - dan bukan di
            // ujung tabel, supaya ia terbaca sebagai bagian data peserta
            // alih-alih tambahan yang menempel belakangan.
            $table->boolean('butuh_perangkat')->default(false)->after('class_id');

            // Indeks parsial tidak didukung MySQL 5.7, jadi indeksnya biasa.
            // Yang membaca kolom ini adalah penempatan (mengambil seluruh
            // peserta bertanda satu event) - dan itu selalu disertai
            // `event_id`, sehingga indeks gabungan lebih berguna daripada
            // indeks tunggal.
            $table->index(['event_id', 'butuh_perangkat'], 'peserta_butuh_perangkat');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropIndex('peserta_butuh_perangkat');
            $table->dropColumn('butuh_perangkat');
        });
    }
};
