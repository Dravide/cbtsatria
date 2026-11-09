<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak kocokan penempatan peserta - satu baris per EVENT.
 *
 * ---------------------------------------------------------------------
 * Per event, bukan per sesi - dan itu keputusan yang dibalik
 * ---------------------------------------------------------------------
 * Bentuk pertama migrasi ini menyimpan satu baris per SESI. Yang membatalkannya
 * bukan kerapian, melainkan pertanyaan yang dijawab Admin di depan pintu:
 * "anak saya di ruang berapa?". Dengan kocokan per sesi, jawabannya berbeda
 * untuk setiap mapel - R-03 pada Matematika, R-07 pada Bahasa Indonesia - dan
 * siswa yang menghafal satu ruang menemukan dirinya di ruang lain pada ujian
 * berikutnya. Sekolah yang memakai penempatan campuran memang menghendaki satu
 * peta untuk seluruh ujian.
 *
 * Dibalik pada 07 Nov 2026, SEBELUM satu baris pun pernah terisi, sehingga tidak
 * ada daftar cetak yang menjadi tidak berlaku karena perubahan ini.
 *
 * ---------------------------------------------------------------------
 * Mengapa benihnya perlu disimpan
 * ---------------------------------------------------------------------
 * Ruang siswa bukan angka di layar: ia dicetak, ditempel di pintu, dan
 * dibacakan pengawas. Daftar cetak yang tidak lagi cocok dengan layar adalah
 * daftar yang tidak dipercaya siapa pun - dan yang paling mungkin terjadi
 * sesudahnya adalah Admin menyebar ulang seluruh peserta untuk "memastikan",
 * yang justru membatalkan seluruh daftar yang sudah tercetak.
 *
 * Karena itu kocokannya berbenih: benih yang sama menghasilkan urutan yang
 * sama persis, sehingga halaman ini bisa dibuka ulang kapan saja tanpa
 * mengubah satu kursi pun. Benihnya disimpan supaya Admin bisa menunjuknya
 * saat melapor - "kocokan #7K2M" adalah sesuatu yang bisa diperiksa, sedangkan
 * "sistem yang mengacak" tidak.
 *
 * ---------------------------------------------------------------------
 * Satu baris per event, dan itu dijamin skema
 * ---------------------------------------------------------------------
 * `unique(school_id, event_id)`. Dua baris kocokan untuk satu event berarti dua
 * kebenaran tentang kursi yang sama, dan tidak ada cara memilih yang benar di
 * antara keduanya.
 *
 * Yang TIDAK disimpan di sini adalah kursinya sendiri. Peta `siswa -> ruang`
 * dihitung ulang dari benih ini setiap kali dibutuhkan, lalu DITERAPKAN ke tiap
 * sesi sebagai `exam_participants.exam_session_room_id`. Alasannya ada di
 * PenempatanKocok: satu peta event tidak bisa menyimpan id ruang SESI, karena
 * "R-01" pada sesi Matematika dan "R-01" pada sesi Bahasa Indonesia adalah dua
 * baris yang berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_event_shuffles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');

            // Benih dalam bentuk teks, bukan angka. Yang perlu dibaca manusia
            // adalah "sama atau tidak", bukan nilainya - dan Admin yang
            // menunjuk benihnya di telepon tidak bisa mengeja bilangan 64-bit.
            $table->string('seed', 32);

            // Kapan dikocok, dan oleh siapa. Inilah yang membuat kocokan bisa
            // dipertanggungjawabkan: urutan yang tidak bisa ditelusuri ke satu
            // waktu dan satu orang adalah urutan yang tidak bisa dibantah
            // maupun dipercaya.
            $table->timestamp('shuffled_at');
            $table->unsignedBigInteger('shuffled_by')->nullable();

            // Jumlah peserta yang ikut kocokan saat itu. Disimpan, bukan
            // dihitung ulang: peserta bisa ditarik lagi sesudah dikocok, dan
            // "240 peserta dikocok" adalah fakta tentang kocokan itu, bukan
            // tentang keadaan sekarang.
            $table->unsignedInteger('peserta')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'event_id'], 'kocokan_event_unik');
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'event_id'])
                ->references(['school_id', 'id'])->on('school_events')->cascadeOnDelete();

            // NULL berarti "dikocok oleh proses otomatis" - seeder dan
            // penyiapan contoh masuk lewat sini. RESTRICT, bukan SET NULL:
            // menghapus akun Admin tidak boleh menghapus jejak bahwa kocokan
            // itu pernah ada.
            $table->foreign(['school_id', 'shuffled_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_event_shuffles');
    }
};
