<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel anak pertama: tiga profil.
 *
 * Di sinilah composite foreign key mulai dipasang, lalu diterapkan konsisten
 * di semua tabel anak sesudahnya. Efeknya: baris profil yang menunjuk user
 * dari sekolah lain menjadi MUSTAHIL di lapisan basis data - bukan sekadar
 * dicegah oleh middleware atau policy.
 *
 * Karena itu setiap tabel di sini membawa school_id meski sebenarnya bisa
 * diturunkan dari user_id. school_id itu bukan duplikasi yang malas: ia
 * justru kolom yang membuat composite FK punya pasangan untuk dirujuk.
 *
 * Satu user punya satu profil sesuai perannya (PRD §17 butir 2), jadi
 * unique(['school_id','user_id']) menegakkan one-to-one di tingkat tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('nip')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'user_id']);
            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('nis')->nullable();
            $table->string('nisn')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'user_id']);
            // NIS dan NISN unik per sekolah - bukan global: dua sekolah boleh
            // punya NIS yang kebetulan sama, dan itu memang terjadi.
            $table->unique(['school_id', 'nis']);
            $table->unique(['school_id', 'nisn']);
            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('supervisor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('staff_code')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'user_id']);
            $table->foreign(['school_id', 'user_id'])
                ->references(['school_id', 'id'])->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_profiles');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('teacher_profiles');
    }
};
