<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exam_questions` belum punya `unique(['school_id','id'])`.
 *
 * ---------------------------------------------------------------------
 * Satu baris, dan justru baris itu yang menahan seluruh Fase 7
 * ---------------------------------------------------------------------
 * `attempt_questions.exam_question_id` adalah composite FK
 * (`school_id`, `exam_question_id`) -> (`school_id`, `id`), dan MySQL
 * menolak setiap composite FK yang tabel tujuannya tidak punya kunci unik
 * tepat pada pasangan kolom yang dirujuknya:
 *
 *   "6125 Failed to add the foreign key constraint. Missing unique key for
 *    constraint 'attempt_questions_school_id_exam_question_id_foreign' in
 *    the referenced table 'exam_questions'"
 *
 * Tabel tujuannya punya `ujian_butir_unik` dan sebuah index, tetapi tidak
 * satu pun dari keduanya memuat pasangan (`school_id`, `id`) yang
 * dituntut. Setiap tabel lain di skema ini sudah punya pasangan itu sejak
 * tabelnya lahir; tabel ini terlewat, dan tidak ada yang menyadarinya
 * sampai ada tabel pertama yang merujuknya per baris.
 *
 * ---------------------------------------------------------------------
 * Mengapa migrasi terpisah, bukan menyunting migrasi aslinya
 * ---------------------------------------------------------------------
 * `exam_questions` lahir di Fase 4 dan sudah berjalan di basis data yang
 * dipakai. Menyunting migrasi lamanya berarti `migrate` biasa pada basis
 * data yang sudah punya tabelnya tidak melakukan apa pun, seolah
 * perbaikannya tidak ada -- dan galatnya muncul kembali hanya di mesin yang
 * melakukan `migrate:fresh`. Migrasi baru berlaku di keduanya.
 *
 * Nomornya `0007_..._000016`, di antara `0005_` dan `0006_` secara alfabet
 * tidak masalah karena bergantung pada tabel yang sudah ada sejak `0005_`;
 * yang menentukan urutan adalah nomor ORANG TUA-nya, bukan namanya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->unique(['school_id', 'id'], 'ujian_butir_id_unik');
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropUnique('ujian_butir_id_unik');
        });
    }
};
