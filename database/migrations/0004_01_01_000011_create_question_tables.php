<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank soal - PRD §16.5, wireframe-peran.html plate 02-05.
 *
 * ---------------------------------------------------------------------
 * question_items dan question_package_items: dua nama yang mudah tertukar
 * ---------------------------------------------------------------------
 * Keduanya ada di berkas ini, dan bedanya bukan gaya penamaan:
 *
 *   question_items         = butir JODOH/URUT milik SATU soal. Isi soal.
 *   question_package_items = daftar isi sebuah PAKET. Daftar pustaka.
 *
 * Yang pertama menjawab "pasangan mana yang benar", yang kedua menjawab
 * "soal mana saja yang ada di ujian ini". Menyatukannya berarti satu tabel
 * yang separuh barisnya tidak berlaku untuk separuh pertanyaan - dan setiap
 * kueri di atasnya harus ingat untuk menyaring.
 *
 * ---------------------------------------------------------------------
 * Setiap unique diberi nama eksplisit, bukan hanya yang panjang
 * ---------------------------------------------------------------------
 * MySQL menolak nama indeks lebih dari 64 karakter dengan
 * "1059 Identifier name ... is too long", dan Laravel membangkitkan nama itu
 * dari daftar kolomnya. Empat kali di fase sebelumnya nama otomatis lolos
 * atau menembus batas secara tidak terduga:
 *
 *   teaching_assignments, event_teacher_assignments, exam_session_rooms,
 *   session_report_signatures
 *
 * Di sini yang paling panjang adalah
 * `question_package_items_school_id_question_package_id_question_id_unique`
 * - 70 karakter, pasti gagal. Tetapi menamai HANYA yang panjang berarti
 * setiap tabel baru harus dihitung dulu panjang namanya, dan satu salah
 * hitung berhenti sebagai galat migrasi yang membingungkan.
 *
 * Karena itu SELURUH unique di berkas ini bernama eksplisit. Aturannya
 * sederhana dan tidak perlu dihitung.
 *
 * ---------------------------------------------------------------------
 * SET NULL mustahil pada composite FK yang membawa school_id NOT NULL
 * ---------------------------------------------------------------------
 * Seluruh tabel di sini memakai composite foreign key (school_id, x) ->
 * (school_id, id), yang menuntut school_id NOT NULL. Akibatnya ON DELETE
 * SET NULL tidak bisa dipakai: ia akan mencoba mengosongkan school_id juga,
 * dan MySQL menolaknya.
 *
 * Yang dipakai adalah cascade (baris anak mati bersama induknya - benar
 * untuk opsi yang kehilangan soalnya) atau restrict (induknya tidak boleh
 * dihapus - benar untuk mapel yang masih dipakai soal).
 *
 * Kolom NULLABLE di dalam composite tetap boleh: MySQL melewati pemeriksaan
 * FK bila salah satu kolomnya NULL (MATCH SIMPLE). question_media.option_id
 * memakainya, persis seperti exam_tokens.created_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |------------------------------------------------------------------
        | questions - satu soal di bank soal
        |------------------------------------------------------------------
        | Berdiri di luar event. Soal adalah milik Guru dan sekolah, bukan
        | milik satu ujian (wireframe plate 02), dan kolom event_id TIDAK ADA
        | di sini dengan sengaja: soal yang sama boleh dipakai di tiga ujian
        | berbeda, dan kolom itu akan memaksanya memilih salah satu.
        |
        | `code` unik PER MAPEL, bukan per sekolah: kode "Q-1042" yang sama
        | di Matematika dan di Fisika adalah dua soal yang berbeda, dan
        | memaksanya unik lintas mapel berarti Guru Biologi tidak boleh
        | memakai penomoran yang sudah dipakai Guru Fisika.
        */
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('subject_id');

            $table->string('code', 40);
            $table->string('title', 255);
            $table->string('question_type', 30);

            // Mudah/sedang/sulit. Diisi Guru, BUKAN dihitung sistem - sampai
            // ada data jawaban siswa (Fase 8), "tingkat kesulitan" hanya bisa
            // berarti "perkiraan penyusunnya", dan kolom yang mengaku lebih
            // tahu daripada pengisinya adalah kolom yang berbohong.
            $table->string('difficulty', 20)->default('sedang');

            // Isi soal. Bentuknya berbeda untuk kesembilan jenis, dan itulah
            // alasan ia JSON: kolom bersama untuk sembilan bentuk berarti
            // sembilan kolom yang saling kosong.
            $table->json('prompt_json');
            $table->json('answer_key_json')->nullable();
            $table->json('scoring_config_json')->nullable();
            $table->json('explanation_json')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'subject_id', 'code'], 'soal_kode_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'subject_id', 'status'], 'soal_mapel_status');

            $table->foreign(['school_id', 'created_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();

            // restrict, bukan cascade: menghapus mapel tidak boleh
            // menghapus soal yang mungkin masih dirujuk paket di tahun
            // ajaran lain. Mapel yang masih punya soal memang harus ditolak.
            $table->foreign(['school_id', 'subject_id'])
                ->references(['school_id', 'id'])->on('subjects')->restrictOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | question_options - pilihan jawaban
        |------------------------------------------------------------------
        | Dipakai PilihanGanda, JawabanGanda, BenarSalah, dan Media yang
        | bermekanisme pilihan. `is_correct` ada di sini ALIH-ALIH hanya di
        | answer_key_json, dan keduanya memang menyimpan hal yang sama.
        |
        | Itu duplikasi yang disengaja: answer_key_json adalah bentuk yang
        | dibekukan ke snapshot, sementara is_correct adalah bentuk yang
        | bisa dikueri saat Guru menyusun - "tampilkan soal yang kuncinya
        | kosong" adalah pertanyaan yang tidak bisa dijawab JSON tanpa
        | memindai seluruh baris. Yang menjaga keduanya tetap sama adalah
        | BankSoalService: satu penulis, satu transaksi.
        */
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->string('option_key', 8);
            $table->json('content_json');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'question_id', 'option_key'], 'opsi_kunci_unik');
            // Induk composite FK question_media.option_id. Tanpa baris ini
            // MySQL menolak dengan 6125 "Missing unique key for constraint"
            // yang menunjuk nama constraint, bukan tabel yang kurang - dan
            // itu pesan yang menuntun ke tempat yang salah.
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'question_id', 'position'], 'opsi_urutan');

            $table->foreign(['school_id', 'question_id'])
                ->references(['school_id', 'id'])->on('questions')->cascadeOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | question_items - butir jodoh/urut milik SATU soal
        |------------------------------------------------------------------
        | `item_group` memisahkan dua kelompok pada soal menjodohkan (kiri
        | dan kanan). Pada soal mengurutkan hanya satu kelompok yang terisi,
        | dan itu bukan keadaan yang perlu ditolak: soal urut memang tidak
        | punya dua sisi.
        |
        | `correct_match_key` menunjuk item_key pasangannya; `correct_position`
        | menyimpan urutan benarnya. Keduanya nullable karena satu baris hanya
        | memakai salah satunya, dan yang tidak dipakai memang kosong.
        */
        Schema::create('question_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->string('item_group', 10)->default('kiri');
            $table->string('item_key', 16);
            $table->json('content_json');
            $table->string('correct_match_key', 16)->nullable();
            $table->unsignedSmallInteger('correct_position')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'question_id', 'item_key'], 'butir_item_unik');
            $table->index(['school_id', 'question_id', 'item_group'], 'butir_grup');

            $table->foreign(['school_id', 'question_id'])
                ->references(['school_id', 'id'])->on('questions')->cascadeOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | question_media - lampiran soal
        |------------------------------------------------------------------
        | Disimpan di disk privat `local`, seperti berkas berita acara Fase 6.
        | TIDAK ada symlink public/storage: soal yang belum diujikan adalah
        | bahan yang belum boleh dibaca siapa pun yang menebak namanya.
        |
        | `option_id` nullable - media boleh melekat pada soal atau pada satu
        | pilihan jawaban. Pola composite FK dengan kolom nullable: MySQL
        | melewati pemeriksaannya saat NULL, sehingga barisnya sah.
        */
        Schema::create('question_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('option_id')->nullable();

            $table->string('media_type', 20);
            $table->string('mime_type', 100);
            $table->string('storage_path', 255);

            // Nama asli disimpan terpisah dari nama berkas di disk: yang di
            // disk diacak supaya tidak bisa ditebak, yang di sini supaya
            // Guru mengunduhnya dengan nama yang ia kenali.
            $table->string('nama_asli', 255);
            $table->unsignedInteger('ukuran')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'question_id'], 'media_soal');

            $table->foreign(['school_id', 'question_id'])
                ->references(['school_id', 'id'])->on('questions')->cascadeOnDelete();

            $table->foreign(['school_id', 'option_id'])
                ->references(['school_id', 'id'])->on('question_options')->cascadeOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | essay_rubric_criteria - rubrik penilaian uraian
        |------------------------------------------------------------------
        | PRD §16.5 menamainya tabel tersendiri, dan itu tepat: rubrik
        | dibaca baris demi baris oleh Guru saat mengoreksi (Fase 8), bukan
        | sebagai satu blok JSON. Menyimpannya di explanation_json berarti
        | laman koreksi harus mengurai JSON untuk menampilkan daftar centang.
        */
        Schema::create('essay_rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->string('criterion', 255);
            $table->text('description')->nullable();
            $table->decimal('max_score', 6, 2)->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['school_id', 'question_id', 'position'], 'rubrik_urutan');

            $table->foreign(['school_id', 'question_id'])
                ->references(['school_id', 'id'])->on('questions')->cascadeOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | question_packages - paket soal
        |------------------------------------------------------------------
        | Paket TIDAK PERNAH memiliki soal. Ia hanya menunjuknya lewat
        | question_package_items, dan blueprint `:861` menyatakan aturannya:
        | "soal tetap disimpan di bank soal lebih dulu, lalu dirujuk oleh
        | paket. Paket tidak pernah memiliki soal secara langsung, dan
        | menghapus paket tidak pernah menghapus soal."
        |
        | Nama paket unik per PEMBUAT, bukan per sekolah. Dua Guru yang sama-
        | sama menamai paketnya "UAS Matematika X" adalah dua paket yang
        | berbeda, dan memaksanya unik lintas sekolah berarti Guru kedua
        | tidak boleh memakai nama yang wajar.
        */
        Schema::create('question_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('subject_id');
            $table->string('name', 150);
            $table->string('grade_level', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->unique(['school_id', 'created_by', 'name'], 'paket_nama_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'subject_id', 'status'], 'paket_mapel_status');

            $table->foreign(['school_id', 'created_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();

            $table->foreign(['school_id', 'subject_id'])
                ->references(['school_id', 'id'])->on('subjects')->restrictOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | question_package_items - daftar isi sebuah paket
        |------------------------------------------------------------------
        | `points_override` nullable dengan arti yang pasti: null berarti
        | "pakai bobot soalnya". Nol berarti "soal ini tidak berbobot di
        | paket ini" - dan itu permintaan yang sah (soal bonus yang tidak
        | dihitung). Menyatukan keduanya dengan default 0 berarti setiap
        | butir yang tidak disetel Guru diam-diam tidak bernilai.
        */
        Schema::create('question_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_package_id');
            $table->unsignedBigInteger('question_id');
            $table->decimal('points_override', 6, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Nama ini 70 karakter bila dibangkitkan otomatis. Lihat catatan
            // di kepala berkas.
            $table->unique(
                ['school_id', 'question_package_id', 'question_id'],
                'paket_butir_unik',
            );
            $table->index(['school_id', 'question_package_id', 'position'], 'paket_butir_urutan');

            $table->foreign(['school_id', 'question_package_id'])
                ->references(['school_id', 'id'])->on('question_packages')->cascadeOnDelete();

            // restrict: soal yang masih dipakai satu paket tidak boleh
            // dihapus diam-diam. Guru harus mengeluarkannya dulu, dan
            // langkah itu terlihat.
            $table->foreign(['school_id', 'question_id'])
                ->references(['school_id', 'id'])->on('questions')->restrictOnDelete();
        });

        /*
        |------------------------------------------------------------------
        | question_import_batches - satu berkas yang sedang diimpor
        |------------------------------------------------------------------
        | Impor berjalan tiga langkah dan tabel ini adalah keadaan di antara
        | keduanya: unggah -> pratinjau -> konfirmasi. Tanpa baris ini,
        | "pratinjau" harus berarti "parse ulang berkasnya", dan Guru yang
        | menekan Konfirmasi lima menit kemudian akan menyimpan hasil parse
        | yang berbeda dari yang ia lihat.
        |
        | `berkas_hash` unik per (sekolah, guru) - mengunggah ulang berkas
        | yang sama adalah dua kali impor yang sama, dan itu satu-satunya
        | cara idempotensi ini bisa ditegakkan tanpa membandingkan isi soal
        | satu per satu.
        |
        | `pratinjau_json` menyimpan hasil validasi per baris BESERTA nomor
        | barisnya, karena itulah yang diminta wireframe plate 04: "Guru
        | yang mengimpor 50 soal harus tahu baris mana yang perlu diperbaiki
        | - bukan menerima pesan 'impor gagal' tanpa alamat."
        */
        Schema::create('question_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('question_package_id')->nullable();

            $table->string('file_path', 255);
            $table->string('nama_asli', 255);
            $table->string('berkas_hash', 64);
            $table->json('pratinjau_json')->nullable();

            $table->string('status', 20)->default('menunggu');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('error_report_path', 255)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['school_id', 'teacher_id', 'berkas_hash'],
                'impor_berkas_unik',
            );
            $table->index(['school_id', 'teacher_id', 'status'], 'impor_guru_status');

            $table->foreign(['school_id', 'teacher_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();

            // nullable di dalam composite: baris sah saat NULL, dan itu
            // memang keadaannya - impor boleh tidak menuju paket mana pun.
            $table->foreign(['school_id', 'question_package_id'])
                ->references(['school_id', 'id'])->on('question_packages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Urutan terbalik dari pembuatannya. Anak dulu, induk belakangan -
        // dan itu bukan kerapian: menghapus `questions` sebelum
        // `question_package_items` ditolak FK restrict, dan migrasi rollback
        // berhenti separuh jalan.
        Schema::dropIfExists('question_import_batches');
        Schema::dropIfExists('question_package_items');
        Schema::dropIfExists('question_packages');
        Schema::dropIfExists('essay_rubric_criteria');
        Schema::dropIfExists('question_media');
        Schema::dropIfExists('question_items');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
    }
};
