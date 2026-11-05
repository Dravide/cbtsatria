<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai, koreksi uraian, dan analisis butir - PRD §13.4, §16.9, plate 06-08 & 21.
 *
 * ---------------------------------------------------------------------
 * Tiga tabel, dan pembagiannya mengikuti tiga pertanyaan yang berbeda
 * ---------------------------------------------------------------------
 *
 *   essay_gradings    "berapa poin untuk jawaban INI, dan mengapa" - satu
 *                     baris per jawaban uraian yang dikoreksi.
 *   exam_results      "berapa nilai AKHIR anak ini" - satu baris per attempt,
 *                     dan justru karena itu ia bisa dijumlahkan, dirata-rata,
 *                     dan dipublikasikan.
 *   question_analyses "apakah butir INI masih layak dipakai" - satu baris per
 *                     butir ujian, dan hanya ada setelah ada jawaban.
 *
 * Ketiganya sengaja TIDAK digabung. `exam_results` tidak bisa menyimpan
 * rubrik per kriteria (satu attempt punya banyak jawaban uraian), dan
 * `essay_gradings` tidak bisa menjawab "nilai akhir Ahmad" tanpa menjumlahkan
 * seluruh jawabannya setiap kali laporan dibuka.
 *
 * ---------------------------------------------------------------------
 * `exam_results` satu baris per ATTEMPT, bukan per peserta
 * ---------------------------------------------------------------------
 * PRD §9.5 mengizinkan `max_attempts` lebih dari satu, dan peserta yang
 * mengulang punya dua pengerjaan. Satu baris per peserta berarti attempt mana
 * yang dinilai menjadi pertanyaan yang harus dijawab kolom tambahan; satu
 * baris per attempt membuatnya tidak perlu ditanyakan.
 *
 * `unique(['school_id','exam_attempt_id'])` menegakkannya, dan disebut
 * blueprint `:461` kata per kata.
 *
 * ---------------------------------------------------------------------
 * `grading_status` menyimpan EMPAT keadaan, dan yang keempat bukan nilai
 * yang lebih tinggi dari yang ketiga
 * ---------------------------------------------------------------------
 * PRD §13.4: Not Graded -> Partially Graded -> Fully Graded -> Published.
 *
 * Terlihat seperti tangga, dan pada kenyataannya bukan. Tiga yang pertama
 * adalah keadaan PENILAIAN, yang keempat adalah keadaan PUBLIKASI, dan
 * keduanya bergerak sendiri-sendiri: hasil yang sudah `fully_graded` bisa
 * ditarik kembali ke `partially_graded` saat Admin membuka kunci koreksi
 * (blueprint keputusan #10) - sementara `published_at` di baris yang sama
 * tetap terisi, karena publikasinya tidak dibatalkan.
 *
 * Kolomnya string ber-enum, sama seperti `users.role` dan
 * `school_events.status`: migrasi lama tetap terbaca, seluruh kode memakai
 * `StatusNilai` lewat casts().
 *
 * ---------------------------------------------------------------------
 * `pending_essays` disimpan, bukan dihitung
 * ---------------------------------------------------------------------
 * Tombol publikasi di plate 21 NONAKTIF selama masih ada uraian belum
 * dinilai, dan pertanyaan itu ditanyakan pada setiap penyegaran laman
 * publikasi - untuk SELURUH ujian dalam event. Menghitungnya berarti
 * menggabungkan `student_answers` ke `exam_questions` untuk setiap attempt
 * setiap kali laman dibuka, tepat ketika seluruh sekolah sedang mengerjakan
 * ujian.
 *
 * Nilainya diperbarui oleh satu penulis (`PenilaiUraian` saat skor ditulis,
 * dan `PenghitungHasil` saat hasil dihitung ulang), sehingga tidak ada jalur
 * kedua yang bisa membuatnya berbeda dari kenyataan.
 *
 * ---------------------------------------------------------------------
 * Enam jebakan yang sudah pernah menggigit, dan pencegahannya
 * ---------------------------------------------------------------------
 * 1. Nama indeks otomatis menembus 64 karakter. Setiap unique dan index
 *    gabungan di sini diberi nama eksplisit - bukan hanya yang panjang.
 *    `essay_gradings_school_id_student_answer_id_unique` berjumlah 52
 *    karakter dan lolos, tetapi aturannya tetap: nama eksplisit.
 * 2. Composite FK menuntut `unique(['school_id','id'])` di tabel induknya
 *    atau MySQL menolak dengan galat 6125. `exam_attempts`,
 *    `student_answers`, dan `exam_questions` sudah memilikinya.
 * 3. `SET NULL` mustahil pada composite FK yang membawa `school_id` NOT NULL
 *    (galat 1005). `teacher_id` karena itu `restrictOnDelete()` - lihat
 *    catatan di bawah.
 * 4. Kolom nullable di dalam composite FK dilewati MySQL (MATCH SIMPLE).
 *    `teacher_id` NOT NULL, jadi tidak berlaku di sini; tetapi
 *    `exam_results.published_by` nullable dan memang mengandalkan perilaku
 *    itu.
 * 5. Urutan berkas migrasi menentukan urutan eksekusi. Berkas ini `0008_`
 *    dan bergantung pada tabel `0005_`, `0006_`, dan `0007_` - ketiganya
 *    sudah ada.
 * 6. Pemilihan kolom parsial di eager load membisukan kolom yang tidak
 *    disebut. Tidak ada di migrasi, tetapi berlaku bagi seluruh pembaca
 *    tabel ini.
 *
 * ---------------------------------------------------------------------
 * Mengapa `teacher_id` DAN `published_by` sama-sama RESTRICT
 * ---------------------------------------------------------------------
 * Keduanya menunjuk `users`, dan keduanya memakai `restrictOnDelete()` -
 * bukan karena aturannya sama, melainkan karena `SET NULL` MUSTAHIL pada
 * composite FK yang membawa `school_id` NOT NULL (galat 1830; jebakan yang
 * sudah pernah menggigit).
 *
 * Yang membedakan keduanya adalah MAKNANYA, dan itu tetap terjaga:
 *
 *   teacher_id    Koreksi uraian menempel pada satu orang. Nilainya menjadi
 *                 dasar nilai akhir yang bisa dipersoalkan siswa, dan guru
 *                 yang dihapus sementara koreksinya masih berlaku adalah
 *                 nilai yang tidak bisa dipertanggungjawabkan.
 *   published_by  Hasil yang sudah dibaca 300 siswa tidak menjadi rahasia
 *                 lagi hanya karena akun yang menerbitkannya dihapus.
 *
 * Yang membuat perbedaan itu tidak menuntut ON DELETE yang berbeda: `User`
 * memakai `SoftDeletes`. "Menghapus admin" berarti mengisi `deleted_at`, dan
 * FK-nya tidak pernah dievaluasi. Nama pelakunya tetap tercatat, dan
 * penghapusan keras justru ditolak - yang memang benar untuk kedua kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('essay_gradings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_answer_id');
            $table->unsignedBigInteger('teacher_id');

            // Poin total adalah JUMLAH dari `criteria_json`, disimpan supaya
            // laporan tidak perlu menjumlahkan JSON. Yang menegakkan
            // konsistensinya adalah PenilaiUraian, satu-satunya penulis tabel
            // ini - rubrik yang dicentang dan poin totalnya tidak bisa
            // berbeda asal keduanya ditulis oleh satu method.
            $table->decimal('score', 8, 2)->default(0);

            // Rincian per kriteria: [{"kriteria":"Langkah benar","poin":4}].
            // Disimpan karena poin total saja tidak bisa dipertanggungjawabkan
            // saat siswa bertanya "kenapa saya dapat 7 dari 8".
            $table->json('criteria_json');

            $table->text('feedback')->nullable();
            $table->string('status')->default('final');
            $table->timestamp('graded_at');
            $table->timestamps();

            $table->unique(['school_id', 'student_answer_id'], 'koreksi_jawaban_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'teacher_id', 'graded_at'], 'koreksi_guru_waktu');

            $table->foreign(['school_id', 'student_answer_id'])
                ->references(['school_id', 'id'])->on('student_answers')->cascadeOnDelete();

            $table->foreign(['school_id', 'teacher_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_attempt_id');

            // Tiga kolom skor, dan pemisahannya disengaja: plate 07
            // menampilkan nilai objektif dan nilai uraian sebagai DUA kolom
            // sebelum dijumlahkan, karena Guru perlu melihat dari mana angka
            // akhirnya datang. Kolom tunggal menyembunyikan bahwa separuh
            // kelas belum dikoreksi.
            $table->decimal('automatic_score', 8, 2)->default(0);
            $table->decimal('essay_score', 8, 2)->default(0);
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('maximum_score', 8, 2)->default(0);

            // PRD §13.4: (total / maksimal) x 100. Disimpan, bukan dihitung
            // setiap dibaca: satu nilai yang dibulatkan berbeda di dua tempat
            // adalah dua nilai yang berbeda.
            $table->decimal('final_percentage', 5, 2)->default(0);

            $table->string('grading_status');

            // Jumlah uraian yang belum dikoreksi. Lihat catatan kepala berkas
            // tentang mengapa ia disimpan alih-alih dihitung.
            $table->unsignedInteger('pending_essays')->default(0);

            // Diisi saat event dipublikasikan; NULL berarti hasilnya belum
            // boleh dilihat siswa. Boleh NULL sementara `grading_status`
            // sudah `published`? Tidak - dan yang menjaganya PublikasiHasil,
            // bukan constraint: dua kolom yang saling mensyaratkan tidak
            // bisa dinyatakan sebagai CHECK yang portabel di MySQL 8 tanpa
            // menduplikasi aturannya di PHP.
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();

            $table->timestamp('dihitung_pada');
            $table->timestamps();

            $table->unique(['school_id', 'exam_attempt_id'], 'hasil_attempt_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'grading_status'], 'hasil_status_indeks');
            $table->index(['school_id', 'published_at'], 'hasil_terbit_indeks');

            $table->foreign(['school_id', 'exam_attempt_id'])
                ->references(['school_id', 'id'])->on('exam_attempts')->cascadeOnDelete();

            // RESTRICT dengan alasan yang sama seperti `teacher_id` di atas:
            // SET NULL mustahil pada composite yang membawa `school_id`.
            // SoftDeletes membuatnya tidak pernah dievaluasi.
            $table->foreign(['school_id', 'published_by'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('question_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_question_id');

            // Dua angka yang dibaca plate 08, dan keduanya disimpan karena
            // keduanya diturunkan dari SELURUH jawaban ujian itu. Menghitung
            // daya beda saat laman dibuka berarti mengurutkan seluruh peserta
            // berdasarkan nilai, lalu memeriksa kelompok atas dan bawah untuk
            // SETIAP butir - pekerjaan yang tumbuh kuadratik.
            $table->unsignedInteger('answered_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->decimal('percent_correct', 5, 2)->default(0);

            // Indeks daya beda: -1,00 sampai 1,00. Negatif berarti butirnya
            // menghukum siswa yang pintar - hampir selalu karena kuncinya
            // tertukar, dan itu yang ditulis plate 08 di kotak merahnya.
            $table->decimal('discrimination', 5, 3)->nullable();

            // `baik` | `sulit` | `terlalu mudah` | `periksa kunci`.
            $table->string('verdict');
            $table->timestamp('dihitung_pada');
            $table->timestamps();

            $table->unique(['school_id', 'exam_question_id'], 'analisis_butir_unik');
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'verdict'], 'analisis_putusan_indeks');

            $table->foreign(['school_id', 'exam_question_id'])
                ->references(['school_id', 'id'])->on('exam_questions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_analyses');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('essay_gradings');
    }
};
