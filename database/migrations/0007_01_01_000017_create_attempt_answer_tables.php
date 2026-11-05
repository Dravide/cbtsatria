<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jawaban peserta ujian - PRD §16.8, wireframe-peran.html plate 22-27.
 *
 * ---------------------------------------------------------------------
 * Dua tabel, dan pemisahannya adalah inti mesin ujian
 * ---------------------------------------------------------------------
 *
 *   attempt_questions = URUTAN soal untuk satu attempt, dan pengacakan
 *                       yang berlaku untuk attempt ITU saja.
 *   student_answers   = jawaban satu butir, beserta versinya.
 *
 * Pemisahan itu bukan normalisasi demi normalisasi. PRD §9.2 memberi ujian
 * `shuffle_questions` dan `shuffle_options`, dan pengacakan yang disimpan di
 * `exam_questions` berarti setiap peserta melihat urutan yang sama - yaitu
 * tidak mengacak apa pun. Urutannya karena itu melekat pada attempt, dan
 * satu attempt tidak boleh punya dua urutan.
 *
 * `option_order_json` dan `item_order_json` menyimpan urutan pilihan yang
 * benar-benar dilihat peserta. Tanpa keduanya, jawaban "A" tidak bisa
 * ditafsirkan di kemudian hari: A yang mana? Menyimpan urutannya membuat
 * jawaban tetap terbaca meski `exam_questions` berubah bentuk sesudahnya -
 * dan snapshot memang boleh berubah bentuk, karena itu sifatnya.
 *
 * ---------------------------------------------------------------------
 * `version` di student_answers menegakkan optimistic concurrency
 * ---------------------------------------------------------------------
 * PRD §11.4 menuntutnya secara eksplisit: "Sistem menggunakan versioning
 * atau mekanisme optimistic concurrency untuk mengelola perubahan
 * jawaban." Dua tab yang sama-sama menyimpan jawaban soal 12 tidak boleh
 * saling menimpa tanpa ada yang tahu - di sini tab kedua ditolak, dan siswa
 * melihat jawaban mana yang tersimpan.
 *
 * Kolomnya bertipe `unsigned integer` yang dinaikkan di dalam transaksi
 * dengan `lockForUpdate()`. Pembandingan di sisi klien saja tidak cukup:
 * klien yang memutuskan versi mana yang menang adalah klien yang menentukan
 * jawaban mana yang benar.
 *
 * ---------------------------------------------------------------------
 * `is_correct` dan `score_awarded` ADA SEJAK SEKARANG, penyebabnya TIDAK
 * ---------------------------------------------------------------------
 * Keduanya kolom penilaian, dan penilaian otomatis adalah Fase 8. Ia
 * ditambahkan kini karena dua alasan yang berbeda:
 *
 *   1. `is_correct` nullable berarti "belum dinilai", dan itu keadaan yang
 *      benar untuk uraian sampai guru mengoreksinya. Menambahkannya
 *      belakangan berarti setiap baris lama harus diberi nilai default yang
 *      mengarang sesuatu.
 *   2. Fase 8 menghitung nilai dari tabel ini. Kolom yang lahir bersamaan
 *      dengan datanya tidak pernah punya baris yang harus ditafsirkan.
 *
 * Yang TIDAK dibangun Fase 7 adalah penulisnya. Skoring tetap milik Fase 8.
 *
 * ---------------------------------------------------------------------
 * Tiga jebakan yang sudah pernah menggigit, dan pencegahannya di sini
 * ---------------------------------------------------------------------
 * 1. NAMA INDEKS MENEMBUS 64 KARAKTER. Nama otomatis untuk
 *    `attempt_questions_school_id_exam_attempt_id_exam_question_id_unique`
 *    berjumlah 70 karakter. Setiap unique dan index gabungan diberi nama
 *    eksplisit -- aturan yang sudah menjadi kebiasaan sejak Fase 4.
 * 2. `SET NULL` MUSTAHIL PADA COMPOSITE FK YANG MEMBAWA school_id NOT NULL
 *    (galat 1005). Semua relasi di sini memakai `cascadeOnDelete()`:
 *    menghapus attempt yang jawabannya masih tersimpan tidak boleh
 *    meninggalkan jawaban yatim, dan attempt hanya dihapus bila pesertanya
 *    dihapus - yang memang berarti seluruh jejaknya ikut hilang.
 * 3. KOLOM NULLABLE DI DALAM COMPOSITE FK DILEWATI (MATCH SIMPLE).
 *    `exam_questions.question_id` di `exam_attempts` tidak ada di sini
 *    karena tidak ada; yang ada adalah `exam_question_id` NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | attempt_questions - urutan soal untuk satu attempt.
        |
        | `display_order` bukan salinan `exam_questions.position`: posisi
        | adalah urutan paket, dan urutan attempt bisa diacak. Yang menentukan
        | "soal nomor 12" adalah kolom ini.
        */
        Schema::create('attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_attempt_id');
            $table->unsignedBigInteger('exam_question_id');

            $table->unsignedInteger('display_order');

            // Urutan pilihan dan butir yang BENAR-BENAR dilihat peserta.
            // Null berarti "sebagaimana di snapshot" - dan null yang jujur
            // lebih baik daripada salinan yang mengarang urutan yang tidak
            // pernah diacak.
            $table->json('option_order_json')->nullable();
            $table->json('item_order_json')->nullable();

            $table->timestamps();

            $table->unique(
                ['school_id', 'exam_attempt_id', 'exam_question_id'],
                'attempt_soal_unik',
            );
            $table->unique(['school_id', 'exam_attempt_id', 'display_order'], 'attempt_urutan_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'exam_attempt_id'], 'attempt_soal_indeks');

            $table->foreign(['school_id', 'exam_attempt_id'])
                ->references(['school_id', 'id'])->on('exam_attempts')->cascadeOnDelete();

            $table->foreign(['school_id', 'exam_question_id'])
                ->references(['school_id', 'id'])->on('exam_questions')->cascadeOnDelete();
        });

        /*
        | student_answers - jawaban satu butir.
        |
        | `answer_json` mencakup kesembilan jenis soal, dan bentuknya
        | mengikuti `JenisSoal`: {"opsi":["a"]}, {"teks":"7"},
        | {"pasangan":{"k1":"a"}}, {"urutan":["c","a"]}, {"nilai":3.14}.
        | Satu kolom JSON, bukan sembilan kolom - alasannya sama dengan
        | `questions.prompt_json`: sembilan bentuk yang saling mengganggu
        | dalam satu tabel lebar.
        */
        Schema::create('student_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('attempt_question_id');

            $table->json('answer_json')->nullable();

            // Nullable sampai dinilai. Uraian lahir null dan tetap null
            // sampai guru mengoreksinya di Fase 8; butir objektif akan
            // diisi Fase 8 juga. Null berarti "belum dinilai", bukan "salah".
            $table->boolean('is_correct')->nullable();
            $table->decimal('score_awarded', 8, 2)->nullable();

            $table->timestamp('saved_at');
            $table->unsignedInteger('version')->default(1);

            // Ditandai siswa untuk ditinjau (plate 22). Disimpan di sini,
            // bukan di attempt_questions, karena ragu adalah sifat JAWABAN:
            // menandai soal yang belum dijawab tidak bermakna.
            $table->boolean('ditandai')->default(false);

            $table->timestamps();

            $table->unique(['school_id', 'attempt_question_id'], 'jawaban_unik');
            $table->unique(['school_id', 'id']);

            $table->foreign(['school_id', 'attempt_question_id'])
                ->references(['school_id', 'id'])->on('attempt_questions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answers');
        Schema::dropIfExists('attempt_questions');
    }
};
