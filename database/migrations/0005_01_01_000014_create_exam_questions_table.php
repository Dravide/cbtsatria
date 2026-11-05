<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot soal ke dalam ujian - PRD §16.6, blueprint `:312` dan `:446`.
 *
 * ---------------------------------------------------------------------
 * Mengapa berkas ini bernomor 0005 dan bukan 0004
 * ---------------------------------------------------------------------
 * Seluruh sisipan Fase 4 memakai awalan `0004_` supaya sortir setelah tabel
 * event. Berkas ini TIDAK, dan alasannya mekanis: ia memuat
 * `foreign(['school_id','exam_id'])->on('exams')`, sementara `exams` lahir
 * di `0005_01_01_000009` - Fase 5, yang dikerjakan sebelum Fase 4.
 *
 * Dengan awalan 0004, `migrate:fresh` berhenti di sini:
 *
 *   SQLSTATE[HY000]: General error: 1824
 *   Failed to open the referenced table 'exams'
 *
 * Nomor `0005_01_01_000014` menempatkannya SETELAH `exams` dan
 * `exam_tokens`, dan tetap SEBELUM tabel attendance (`0006_...`) yang tidak
 * menyentuhnya. Yang menentukan urutan migrasi adalah nama berkasnya, bukan
 * fase yang mengerjakannya - dan dua hal itu berbeda persis di sini.
 *
 * ---------------------------------------------------------------------
 * Mengapa snapshot, dan mengapa ia tabel tersendiri
 * ---------------------------------------------------------------------
 * Blueprint `:312`: "Begitu paket soal ditetapkan ke ujian, isi soal
 * dibekukan ke exam_questions. Bank soal tetap boleh diedit Guru tanpa
 * merusak ujian yang sedang berjalan. Tanpa ini, satu edit Guru bisa
 * mengubah ujian 300 siswa yang sedang berlangsung."
 *
 * Karena itu tabel ini adalah SALINAN, bukan rujukan. Setiap kolom isi soal
 * ada di sini sebagai JSON utuh, dan membacanya tidak pernah menyentuh
 * tabel `questions` sama sekali.
 *
 * ---------------------------------------------------------------------
 * Satu baris lebar, TANPA tabel anak - dan itu keputusan yang disengaja
 * ---------------------------------------------------------------------
 * Bentuk yang lebih lazim adalah menormalkan seperti bank soal: satu tabel
 * soal, satu tabel opsi, satu tabel butir jodoh. Snapshot menolak bentuk itu,
 * dan alasannya tiga:
 *
 *   1. Snapshot dibaca sebagai SATU KESATUAN. Saat siswa membuka nomor 7,
 *      yang dibutuhkan adalah seluruh isi nomor 7 - soal, opsi, kunci,
 *      bobot, rubrik - dalam satu pembacaan. Ternormalisasi berarti empat
 *      kueri untuk satu soal, dikali 40 soal, dikali 300 siswa.
 *
 *   2. Snapshot tidak pernah dikueri sebagian. Pertanyaan seperti "soal mana
 *      yang kuncinya kosong" tidak masuk akal di sini: kalau kosong, itu
 *      sudah terjadi di bank soal sebelum dibekukan, dan yang memperbaikinya
 *      adalah Guru di sana.
 *
 *   3. Snapshot harus ABADI dalam bentuknya. Bentuk bank soal boleh berubah
 *      di Fase 8 - kolom baru, jenis soal kesepuluh - dan snapshot yang
 *      ternormalisasi akan ikut berubah, sehingga ujian dua tahun lalu
 *      tiba-tiba berbeda bentuknya. Satu baris lebar dengan JSON beku tidak
 *      bisa berubah, dan itu justru yang diinginkan.
 *
 * Harga yang dibayar jujur: kolom `opsi_json` mungkin akan diduplikasi di
 * `student_answers` (Fase 7) bila jawaban siswa perlu disimpan per opsi.
 * Itu masih lebih murah daripada membongkar snapshot setelah ada ujian
 * berjalan di atasnya.
 *
 * ---------------------------------------------------------------------
 * question_id TANPA foreign key, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * Kolomnya ada murni untuk PENELUSURAN: "soal ini berasal dari butir yang
 * mana". Ia tidak boleh menegakkan apa pun, karena setiap pilihan yang ada
 * salah:
 *
 *   cascade  -> menghapus satu soal di bank akan menghapus snapshotnya, dan
 *               ujian yang sedang berjalan kehilangan satu nomor soalnya.
 *               Persis yang dilarang blueprint.
 *   restrict -> soal yang pernah dipakai ujian tidak bisa dihapus SELAMANYA,
 *               bahkan setelah ujiannya selesai dan diarsipkan.
 *   set null -> mustahil: composite FK membawa school_id NOT NULL, dan
 *               MySQL menolak SET NULL yang mengosongkan kolom itu juga.
 *
 * Tanpa FK, kolomnya jujur tentang dirinya: ia catatan asal-usul, bukan
 * penjamin keutuhan. Snapshot yang utuh justru dibuktikan oleh fakta bahwa
 * ia tidak bergantung pada apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('exam_id');

            // Nomor urut soal di dalam ujian ini. Urutan adalah bagian dari
            // soal ujian - ia menentukan soal mana yang pertama - sehingga
            // ia disalin, bukan dihitung ulang saat dibaca.
            $table->unsignedSmallInteger('position');

            // Bobot yang BERLAKU di ujian ini, sudah memperhitungkan
            // points_override paket. Disimpan hasilnya, bukan rumusnya:
            // paket boleh dilepas setelah ujian lahir, dan bobot ujian tidak
            // boleh ikut berubah saat itu terjadi.
            $table->decimal('bobot', 6, 2)->default(1);

            // Rujukan lunak - lihat catatan di kepala berkas. Sengaja TANPA
            // foreign key.
            $table->unsignedBigInteger('question_id')->nullable();

            /* ---------------------------------------------------------
             | Salinan metadata
             |---------------------------------------------------------
             | Ada di sini meski sudah ada di questions, karena laman
             | pratinjau dan laporan (Fase 8) menampilkan daftar soal
             | ujian tanpa perlu menyentuh bank soal sama sekali.
             */
            $table->string('kode', 40)->nullable();
            $table->string('jenis', 30);
            $table->string('tingkat', 20)->nullable();
            $table->string('judul', 255)->nullable();

            /* ---------------------------------------------------------
             | Salinan isi soal
             |---------------------------------------------------------
             | Nama kolomnya `_json` dan bukan `_json` yang berbeda dari
             | questions: pembaca yang berpindah antara kedua tabel harus
             | menemukan bentuk yang sama. Kolom `soal_json` adalah nama
             | lain dari prompt_json, dan itu disengaja - di sini ia bukan
             | lagi "prompt yang akan disunting", melainkan "soal yang
             | sudah dibekukan".
             */
            $table->json('soal_json');
            $table->json('opsi_json')->nullable();
            $table->json('butir_json')->nullable();
            $table->json('kunci_json')->nullable();
            $table->json('rubrik_json')->nullable();
            $table->json('skor_json')->nullable();
            $table->json('pembahasan_json')->nullable();
            $table->json('media_json')->nullable();

            $table->timestamp('dibekukan_pada')->nullable();
            $table->timestamp('created_at')->nullable();

            // Satu nomor hanya boleh ada sekali di satu ujian. Nama
            // eksplisit: yang dibangkitkan otomatis hanya 47 karakter, jadi
            // sebenarnya muat - tetapi aturan di berkas ini adalah menamai
            // semuanya, tanpa menghitung panjang, supaya tidak ada satu pun
            // yang perlu dihitung.
            $table->unique(['school_id', 'exam_id', 'position'], 'ujian_butir_unik');
            $table->index(['school_id', 'question_id'], 'ujian_asal_soal');

            $table->foreign(['school_id', 'exam_id'])
                ->references(['school_id', 'id'])->on('exams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
