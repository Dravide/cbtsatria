<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data akademik - PRD §16.3.
 *
 * Setiap tabel membawa unique(['school_id','id']) supaya bisa menjadi sasaran
 * composite FK bagi tabel di bawahnya. MySQL mensyaratkan pasangan yang
 * dirujuk adalah prefiks terkiri sebuah index, dan unique ini memenuhinya.
 *
 * Istilah akademik ("aktif", "arsip") sengaja dipisahkan dari istilah event
 * ("berjalan", "selesai"). Wireframe plate 10 menandaskan itu: status event
 * tidak boleh memakai kata "aktif", karena kata itu sudah milik tahun ajaran
 * dan semester. Dua konsep, dua kosakata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');

            // Hanya satu yang boleh true per sekolah. Aturannya TIDAK ditegakkan
            // di sini: MySQL tidak punya partial unique index, dan membuat
            // generated column hanya untuk itu menambah mekanika tanpa
            // mengubah hasilnya. Penegakannya ada di AcademicYearService,
            // dalam satu transaksi yang juga menutup tahun ajaran lain.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'id']);
        });

        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id', 'name']);
            $table->unique(['school_id', 'id']);
            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->cascadeOnDelete();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('grade_level')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'id']);
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
            $table->string('grade_level')->nullable();
            $table->unsignedBigInteger('homeroom_teacher_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();

            // Nama kelas unik PER TAHUN AJARAN, bukan per sekolah: "X-1"
            // muncul lagi setiap tahun, dan itu bukan duplikat.
            $table->unique(['school_id', 'academic_year_id', 'name']);
            $table->unique(['school_id', 'id']);
            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->cascadeOnDelete();

            // RESTRICT, bukan SET NULL, dan itu bukan pilihan gaya.
            //
            // MySQL menolak SET NULL pada composite FK yang menyertakan kolom
            // NOT NULL: untuk men-null-kan homeroom_teacher_id ia harus ikut
            // men-null-kan school_id, dan school_id tidak boleh null. Yang
            // muncul bukan galat yang menjelaskan, melainkan
            // "Column 'school_id' cannot be NOT NULL".
            //
            // RESTRICT justru semantik yang benar di sini: wali kelas tidak
            // boleh dihapus selama masih memegang kelas. Ganti walinya dulu.
            // Karena users memakai soft delete dan Policy melarang delete,
            // batasan ini praktis tidak pernah menyala - ia penjaga integritas,
            // bukan penghalang alur kerja.
            $table->foreign(['school_id', 'homeroom_teacher_id'])
                ->references(['school_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->date('enrollment_date')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();

            // Seorang siswa hanya boleh terdaftar sekali di satu kelas.
            $table->unique(['school_id', 'class_id', 'student_id']);
            $table->index(['school_id', 'student_id']);
            $table->foreign(['school_id', 'class_id'])
                ->references(['school_id', 'id'])->on('classes')->cascadeOnDelete();
            $table->foreign(['school_id', 'student_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->cascadeOnDelete();
        });

        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('class_id');
            $table->timestamps();

            // Guru yang sama tidak ditugaskan dua kali pada mapel dan kelas
            // yang sama di tahun ajaran yang sama.
            // Nama indeks ditulis eksplisit: nama yang dibangkitkan Laravel
            // dari keempat kolom menembus batas 64 karakter MySQL dan gagal
            // dengan "Identifier name ... is too long".
            $table->unique(
                ['school_id', 'teacher_id', 'subject_id', 'class_id'],
                'teaching_unik',
            );
            $table->index(['school_id', 'subject_id']);

            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->cascadeOnDelete();
            $table->foreign(['school_id', 'teacher_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['school_id', 'subject_id'])
                ->references(['school_id', 'id'])->on('subjects')->cascadeOnDelete();
            $table->foreign(['school_id', 'class_id'])
                ->references(['school_id', 'id'])->on('classes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('class_enrollments');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('academic_years');
    }
};
