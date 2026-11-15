<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skor permainan waktu luang - ditambahkan 2026-11-15.
 *
 * Wireframe: wireframe-waktu-luang.html, plate 02, 05, 06.
 *
 * ---------------------------------------------------------------------
 * Mengapa tabelnya menunjuk SISWA, bukan penempatan
 * ---------------------------------------------------------------------
 * Skor ini milik anak, bukan milik satu ujian. Ia bisa bermain pada dua jeda
 * di dua sesi berbeda dalam satu hari, dan papan peringkat kelasnya adalah
 * papan yang sama di keduanya - "rekor Ahmad" tidak boleh terbelah menjadi
 * "rekor Ahmad di Matematika" dan "rekor Ahmad di Fisika".
 *
 * Karena itu barisnya TIDAK ikut hancur saat penempatan dilepas, dan
 * `exam_participant_id` di sini hanya jejak asal-usulnya (`SET NULL` lewat
 * `nullOnDelete()` tidak mungkin pada composite FK yang membawa `school_id`
 * NOT NULL - jadi jejaknya disimpan sebagai dua kolom nullable dan FK-nya
 * tidak dipasang; lihat catatan "jejak" di bawah).
 *
 * ---------------------------------------------------------------------
 * `distance` dan `duration_ms` disimpan, bukan hanya skornya
 * ---------------------------------------------------------------------
 * Server menghitung ulang skor dari keduanya (lihat `PenjagaSkorLuar`), dan
 * hitungan ulang itu hanya bisa diaudit bila angka mentahnya ikut tersimpan.
 * Yang tersimpan sebagai `score` adalah hasil hitung server - bukan angka
 * yang dikirim peramban.
 *
 * ---------------------------------------------------------------------
 * `rejected_reason` nullable, dan null berarti diterima
 * ---------------------------------------------------------------------
 * Kiriman yang ditolak TETAP ditulis. Admin yang melihat "9.400 dalam 12
 * detik" perlu tahu bahwa sistemnya menolak - bukan sekadar menyadari
 * pemain itu tidak muncul di papan. Baris ditolak tidak pernah masuk
 * peringkat, dan penyaringnya satu: `rejected_reason is null`.
 *
 * ---------------------------------------------------------------------
 * Indeks rekor
 * ---------------------------------------------------------------------
 * Papan peringkat selalu membaca "rekor tertinggi per siswa dalam satu
 * kelas". Indeks `(school_id, class_id, score)` melayani pemindaian itu;
 * `(school_id, student_id, score)` melayani "rekor saya" yang dibaca setiap
 * kali halaman dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leisure_game_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('student_id');

            // Kelas saat skor ini dicatat. DISALIN, bukan dibaca lewat join:
            // papan peringkat kelas menanyakan "siapa di kelas ini", dan
            // kelas seorang siswa bisa berganti tahun ajaran berikutnya -
            // sementara skor yang dicetak tahun ini tetap milik kelas tahun
            // ini. Membacanya lewat join akan memindahkan seluruh riwayat
            // skor anak itu ke kelas barunya, dan papan peringkat lama
            // berubah isinya sendiri.
            $table->unsignedBigInteger('class_id')->nullable();

            // Jejak penempatan tempat skor ini dicetak. Null bila penempatan
            // itu sudah dilepas - angka skornya tetap sah, asal-usulnya yang
            // hilang, dan itu urutan yang benar.
            $table->unsignedBigInteger('exam_participant_id')->nullable();

            // Angka yang DIKIRIM peramban.
            $table->unsignedInteger('distance');
            $table->unsignedInteger('duration_ms');

            // Angka yang DIHITUNG server dari keduanya. Inilah yang masuk
            // papan peringkat.
            $table->unsignedInteger('score');

            // Null = diterima. Berisi alasannya bila ditolak, dan barisnya
            // tetap tersimpan untuk audit.
            $table->string('rejected_reason', 40)->nullable();

            $table->timestamp('played_at');
            $table->timestamps();

            // Sasaran composite FK dari tabel lain, pola yang sama dengan
            // seluruh tabel lain di proyek ini.
            $table->unique(['school_id', 'id']);

            // Papan peringkat kelas: rekor tertinggi per siswa di satu kelas.
            $table->index(['school_id', 'class_id', 'score'], 'luang_kelas');

            // Rekor saya - dibaca setiap kali halaman permainan dibuka.
            $table->index(['school_id', 'student_id', 'score'], 'luang_siswa');

            $table->foreign(['school_id', 'student_id'], 'luang_siswa_akun')
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();

            // `restrictOnDelete`, BUKAN `nullOnDelete`, meski kolomnya
            // nullable.
            //
            // MySQL menerapkan `SET NULL` pada SELURUH kolom foreign key-nya,
            // bukan hanya kolom yang kita bayangkan - dan kolom pertama FK
            // ini adalah `school_id` yang NOT NULL. Hasilnya bukan skor yang
            // kelasnya kosong, melainkan galat 1048 saat kelas dihapus, di
            // tempat yang tidak menyebut skor sama sekali. Jebakan yang sama
            // sudah tertulis di migrasi 0022 dan tetap tergigit di sini.
            //
            // Konsekuensinya disengaja: kelas yang masih punya riwayat skor
            // tidak bisa dihapus. Skor itu bukti yang sudah dicetak, dan
            // menghapus kelas adalah cara paling mudah menghapusnya tanpa
            // sadar.
            $table->foreign(['school_id', 'class_id'], 'luang_kelas_asal')
                ->references(['school_id', 'id'])->on('classes')->restrictOnDelete();

            // Penempatan TIDAK dijadikan FK: `nullOnDelete()` mustahil pada
            // composite FK yang membawa `school_id` NOT NULL, dan
            // `cascadeOnDelete()` akan menghapus skor yang sudah dicetak
            // hanya karena Admin melepas penempatan setelah ujiannya. Skor
            // itu bukan milik penempatan - lihat catatan migrasi.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leisure_game_scores');
    }
};
