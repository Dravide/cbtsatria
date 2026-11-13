<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode penempatan yang dipakai satu kocokan event.
 *
 * ---------------------------------------------------------------------
 * Mengapa disimpan bersama benihnya
 * ---------------------------------------------------------------------
 * Denah kursi, tablo, dan daftar presensi menghitung ULANG peta dari benih
 * yang tersimpan - itu yang membuat layar, kertas, dan basis data tidak
 * bisa berselisih. Kalau modenya tidak ikut tersimpan, ketiga laman itu
 * memakai bawaan `campur`, dan event yang disebar dengan mode `kelas`
 * mencetak susunan yang berbeda dari layar. Yang menemukannya pengawas di
 * depan pintu, bukan Admin di layarnya.
 *
 * Bawaannya `campur`: itulah satu-satunya mode yang ada sebelum kolom ini,
 * dan kocokan yang sudah tersimpan harus tetap menghasilkan susunan yang
 * sama persis sesudah migrasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_event_shuffles', function (Blueprint $table) {
            $table->string('mode', 20)->default('campur')->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('exam_event_shuffles', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
