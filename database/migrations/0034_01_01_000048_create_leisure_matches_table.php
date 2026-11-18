<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lomba hitung cepat dua pemain - ditambahkan 2026-11-18.
 *
 * ---------------------------------------------------------------------
 * Soal DISIMPAN sebagai JSON, bukan dibangkitkan ulang dari benih
 * ---------------------------------------------------------------------
 * Kedua pemain harus menerima soal yang SAMA PERSIS, dan itu hanya bisa
 * dijamin dengan satu daftar yang ditulis sekali. Membangkitkan ulang dari
 * sebuah benih berarti dua implementasi generator yang harus menghasilkan
 * urutan identik - di PHP untuk memeriksa dan di peramban untuk menampilkan -
 * dan proyek ini sudah pernah terbakar oleh pasangan angka yang meleset satu
 * (lihat catatan "kotak tabrakan" di `Luang.tsx`).
 *
 * Enam puluh soal berukuran sekitar 8 KB. Itu murah, dan yang dibeli adalah
 * kepastian bahwa lomba tidak pernah menjadi tidak adil karena generatornya
 * berbeda versi.
 *
 * Kunci jawaban ikut tersimpan di JSON yang sama dan TIDAK dikirim ke
 * peramban; yang dikirim hanya teks soal dan pilihannya.
 *
 * ---------------------------------------------------------------------
 * Status hanya menyimpan yang TERMINAL
 * ---------------------------------------------------------------------
 * `menunggu`, `siap`, `berjalan`, dan `selesai` seluruhnya DITURUNKAN dari
 * `mulai_pada` dan jam server. Yang disimpan hanya dua keadaan yang tidak
 * bisa dihitung dari waktu:
 *
 *   - `dibatalkan` - lomba berhenti sebelum waktunya (pemain masuk ujian,
 *     undangan tidak dipakai, atau host membatalkannya sendiri);
 *   - tidak ada - selain itu.
 *
 * Alasannya sama dengan `JendelaLuang`: kolom status yang harus dipindahkan
 * oleh penjadwal adalah kolom yang suatu hari lupa dipindahkan, dan lomba
 * yang tertinggal berstatus `berjalan` sehari kemudian tidak punya gejala
 * yang bisa dilihat siapa pun.
 *
 * ---------------------------------------------------------------------
 * Skor pemain TIDAK disimpan di tabel ini
 * ---------------------------------------------------------------------
 * Ia ditulis ke `leisure_game_scores` yang sudah ada, lewat `match_id`.
 * Dengan begitu rekor, papan peringkat kelas, dan kuota harian bekerja tanpa
 * satu pun perubahan: lomba hanyalah satu permainan yang kebetulan punya
 * lawan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leisure_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // Kode undangan yang diketik pemain kedua. Enam karakter dari
            // alfabet yang membuang huruf mirip (lihat `LombaLuang`).
            $table->string('code', 6);

            $table->string('game_type', 32)->default('hitung_cepat');

            $table->unsignedBigInteger('host_id');
            $table->unsignedBigInteger('guest_id')->nullable();

            // Daftar soal - bentuknya dijaga `LombaLuang`, bukan skema.
            $table->json('soal');

            // Null = belum ada lawan. Begitu pemain kedua masuk, jamnya
            // dipasang SEKALI dan tidak pernah bergeser: kedua layar
            // menghitung mundur ke angka yang sama.
            $table->timestamp('mulai_pada')->nullable();
            $table->timestamp('berlaku_sampai');

            // Null = berjalan atau selesai. Lihat catatan kelas di atas.
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->string('alasan_batal', 40)->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'code'], 'lomba_kode');
            $table->unique(['school_id', 'id'], 'lomba_identitas');

            // Pencarian "lomba yang masih hidup di sekolah ini" - dipakai
            // pemain yang membuka ulang lamannya.
            $table->index(['school_id', 'berlaku_sampai'], 'lomba_hidup');
            $table->index(['school_id', 'host_id', 'created_at'], 'lomba_host');
            $table->index(['school_id', 'guest_id', 'created_at'], 'lomba_tamu');

            // RESTRICT, bukan SET NULL - alasannya sama dengan
            // `event_announcements.created_by`: MySQL menerapkan SET NULL
            // pada SELURUH kolom FK, termasuk `school_id` yang NOT NULL.
            $table->foreign(['school_id', 'host_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();

            $table->foreign(['school_id', 'guest_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::table('leisure_game_scores', function (Blueprint $table) {
            // Null = permainan sendirian. Berisi id lomba bila skor ini lahir
            // dari lomba dua pemain.
            $table->unsignedBigInteger('match_id')->nullable()->after('game_type');

            $table->index(['school_id', 'match_id'], 'luang_lomba');
        });
    }

    public function down(): void
    {
        Schema::table('leisure_game_scores', function (Blueprint $table) {
            $table->dropIndex('luang_lomba');
            $table->dropColumn('match_id');
        });

        Schema::dropIfExists('leisure_matches');
    }
};
