<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publikasi hasil di tingkat EVENT - PRD §13.5, wireframe-admin.html plate 21.
 *
 * ---------------------------------------------------------------------
 * Kolomnya PINDAH dari `exams`, bukan ditambahkan di sampingnya
 * ---------------------------------------------------------------------
 * Sejak Fase 5 ada `exams.publish_results`. Ia diletakkan di sana dengan
 * catatan "pilihan yang lebih sempit, dan perluasan ke tingkat event kelak
 * bisa menimpanya tanpa membongkar kolomnya" - dan itulah yang terjadi
 * sekarang.
 *
 * Yang membuat kolomnya DIPINDAH, bukan ditambahi:
 *
 *   1. Plate 21 menegaskan publikasi adalah SATU aksi untuk seluruh event
 *      ("Publikasi adalah satu aksi untuk seluruh event, bukan per ujian").
 *   2. Satu fakta yang disimpan di dua tempat akan berbeda suatu hari, dan
 *      saat berbeda tidak ada yang tahu mana yang benar. Perilakunya hanya
 *      bisa dijelaskan bila hanya ada satu sumber.
 *
 * `0008_01_01_000019` yang menjatuhkan kolom lama itu; berkas ini hanya
 * menambah yang baru. Dipisah supaya keduanya bisa dibalik sendiri-sendiri
 * saat migrasi bermasalah di tengah jalan.
 *
 * ---------------------------------------------------------------------
 * Empat kolom, dan masing-masing menjawab pertanyaan yang berbeda
 * ---------------------------------------------------------------------
 *
 *   results_published_at   KAPAN hasil dibuka. Nilai ini juga yang menjadi
 *                          kunci koreksi uraian: selama ia null, Guru masih
 *                          boleh menyunting; sesudahnya, tidak.
 *   results_published_by   SIAPA yang membuka. Pertanyaan yang selalu muncul
 *                          sesudah satu angkatan nilai keluar.
 *   results_announced_at   KAPAN siswa diberi tahu. Berbeda dari
 *                          `published_at`, dan itu bukan pemisahan yang
 *                          dipaksakan: hasil bisa sudah dibuka sementara
 *                          pengumumannya sengaja ditahan sampai rapat guru
 *                          selesai. Tombol "Kirim pengumuman" di plate 21
 *                          mengisi kolom ini - tanpa kolomnya, tombol itu
 *                          tidak punya apa pun untuk dikerjakan.
 *   show_objectives        Apakah rincian nilai objektif ikut ditampilkan ke
 *                          siswa. Centang di plate 21.
 *   show_explanations      Apakah pembahasan soal ikut dibuka. TIDAK dicentang
 *                          di plate 21, dan bawaannya memang false: pembahasan
 *                          pilihan ganda sama dengan kuncinya ditambah
 *                          alasannya, dan satu angkatan yang membacanya bisa
 *                          meneruskan soal ke angkatan berikutnya dalam
 *                          hitungan menit.
 *   gradings_locked        Apakah koreksi uraian sedang dikunci.
 *
 * ---------------------------------------------------------------------
 * Mengapa `gradings_locked` terpisah dari `results_published_at`
 * ---------------------------------------------------------------------
 * Dua fakta, dua kolom, dan menggabungkannya akan membuat salah satunya
 * berbohong.
 *
 * Blueprint keputusan #10: koreksi dikunci saat hasil dipublikasikan, dan
 * Admin boleh membukanya kembali dengan alasan yang tercatat. Kalau kuncinya
 * DITURUNKAN dari `results_published_at` ("terkunci berarti sudah terbit"),
 * maka membuka kembali koreksi hanya bisa dilakukan dengan membatalkan
 * publikasinya - dan hasil yang sudah dibaca 300 siswa akan lenyap dari layar
 * mereka karena seorang guru perlu memperbaiki satu poin.
 *
 * Karena itu kolomnya berdiri sendiri: publikasi tetap terbit, koreksi
 * terbuka lagi, dan siswa tidak kehilangan apa pun. Yang menutup koreksi
 * kembali adalah publikasi berikutnya.
 *
 * ---------------------------------------------------------------------
 * Mengapa RESTRICT, meskipun tabelnya bicara "SET NULL"
 * ---------------------------------------------------------------------
 * Yang diinginkan secara semantik adalah `SET NULL`: akun Admin yang dihapus
 * tidak boleh menarik kembali publikasi hasil yang sudah dibaca 300 siswa.
 *
 * Tetapi `SET NULL` MUSTAHIL pada composite FK yang membawa `school_id` NOT
 * NULL - MySQL harus men-null-kan `school_id` juga, dan menolaknya dengan
 * galat 1830. Itu jebakan yang sudah pernah menggigit, dan berkas ini
 * sempat menabraknya.
 *
 * `restrictOnDelete()` karenanya dipakai, dan ia TIDAK merugikan apa pun:
 * `User` memakai `SoftDeletes`, sehingga "menghapus admin" berarti mengisi
 * `deleted_at`, bukan `DELETE FROM users`. Barisnya tetap ada, FK-nya tidak
 * pernah dievaluasi, dan namanya tetap tercatat. Menghapus keras satu akun
 * yang pernah menerbitkan hasil justru yang memang harus ditolak - ia memaksa
 * Admin memindahkan tanggung jawabnya lebih dulu.
 *
 * Pola yang sama dipakai `school_events.created_by` dan `exam_tokens.created_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_events', function (Blueprint $table) {
            $table->timestamp('results_published_at')->nullable()->after('enforce_fullscreen');
            $table->unsignedBigInteger('results_published_by')->nullable()->after('results_published_at');
            $table->timestamp('results_announced_at')->nullable()->after('results_published_by');

            // Bawaan `show_objectives` TRUE karena ia sudah dicentang di plate
            // 21, dan bawaan `show_explanations` FALSE karena tidak dicentang.
            // Bawaan yang berbeda untuk dua kotak yang berdampingan terlihat
            // sewenang-wenang sampai disadari keduanya adalah nilai yang
            // MEMANG dipilih Admin di layar itu.
            $table->boolean('show_objectives')->default(true)->after('results_announced_at');
            $table->boolean('show_explanations')->default(false)->after('show_objectives');

            // Terkunci saat terbit, dan Admin yang membukanya. Lihat catatan
            // kepala berkas: kolom ini TIDAK diturunkan dari
            // `results_published_at`, karena membuka kembali koreksi tidak
            // boleh menarik kembali publikasinya.
            $table->boolean('gradings_locked')->default(false)->after('show_explanations');

            $table->index(['school_id', 'results_published_at'], 'event_terbit_indeks');

            // RESTRICT, dan bukan karena pilihan - lihat catatan kepala
            // berkas. `User` memakai SoftDeletes, sehingga FK ini tidak
            // pernah dievaluasi pada penghapusan yang sebenarnya terjadi.
            $table->foreign(['school_id', 'results_published_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_events', function (Blueprint $table) {
            $table->dropForeign(['school_id', 'results_published_by']);
            $table->dropIndex('event_terbit_indeks');
            $table->dropColumn([
                'results_published_at',
                'results_published_by',
                'results_announced_at',
                'show_objectives',
                'show_explanations',
                'gradings_locked',
            ]);
        });
    }
};
