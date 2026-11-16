<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis sesi: utama atau susulan - PRD §9.3, wireframe plate 16.
 *
 * ---------------------------------------------------------------------
 * Mengapa satu kolom, dan bukan tabel atau ujian kedua
 * ---------------------------------------------------------------------
 * Ujian susulan adalah gelombang KEDUA dari ujian yang sama, bukan ujian
 * baru. Nilai, analisis butir, laporan, dan publikasi semuanya berkunci pada
 * `exam_id`; ujian kedua untuk mapel yang sama akan memunculkan dua baris
 * "Matematika" di plate 21 dan menuntut kode penggabungan di setiap laporan.
 *
 * Karena itu yang ditambahkan hanyalah penanda pada sesi: `jenis`. Seluruh
 * mesin sesi - status, token, pengawas, presensi - sudah menempel pada sesi,
 * bukan pada ujian, sehingga susulan memakainya tanpa satu pun cabang baru.
 *
 * ---------------------------------------------------------------------
 * Bawaan 'utama' supaya seluruh baris lama tetap sah
 * ---------------------------------------------------------------------
 * Sesi yang lahir sebelum kolom ini ada semuanya sesi utama, dan `default`
 * mengisi nilainya tanpa backfill. Sesi susulan satu-satunya yang menulis
 * 'susulan', dan ia selalu ditulis eksplisit.
 *
 * Index `(school_id, exam_id, jenis)` dipakai dua pertanyaan yang sering
 * ditanyakan laman jadwal dan penyaring sebar: "sesi susulan ujian ini" dan
 * "ujian ini punya berapa gelombang".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->string('jenis', 16)->default('utama')->after('session_number');
            $table->index(['school_id', 'exam_id', 'jenis'], 'sesi_jenis_index');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('sesi_jenis_index');
            $table->dropColumn('jenis');
        });
    }
};
