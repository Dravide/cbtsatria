<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondasi tenant: schools + users.
 *
 * Dijalankan di MySQL 8.4.3 (port 3307). Laravel 13 memerlukan >= 8.0.3
 * untuk kolom JSON native; di 5.7 ia diam-diam turun ke LONGTEXT, dan
 * composite foreign key berperilaku berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('npsn')->unique();
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('status')->default('aktif')->index();

            // Bawaan perilaku ujian untuk event BARU. Event yang sudah dibuat
            // menyimpan nilainya sendiri dan tidak ikut berubah - wireframe
            // plate 23 menegaskan itu, dan itu alasan kolomnya ada di sini
            // sebagai nilai awal, bukan sebagai sumber kebenaran tunggal.
            $table->json('settings')->nullable();

            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            // role disimpan sebagai string yang di-backing enum PHP.
            // String, bukan enum MySQL: menambah peran berarti ALTER TABLE
            // pada enum MySQL, sementara validasinya sudah ditegakkan cast.
            $table->string('role')->index();

            $table->string('name');

            // Unik GLOBAL - lihat catatan panjang di App\Domain\Identity\Models\User.
            $table->string('username')->unique();

            // Nullable: siswa tidak selalu punya email. Keunikannya per sekolah.
            $table->string('email')->nullable();

            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'email']);
            $table->index(['school_id', 'role']);
            $table->index(['school_id', 'is_active']);

            // Sasaran composite foreign key untuk tabel anak (Fase 2 dst.).
            // MySQL mensyaratkan pasangan yang dirujuk adalah prefiks terkiri
            // sebuah index; unique(school_id, id) memenuhinya. Tanpa ini,
            // `foreign(['school_id','x'])->references(['school_id','id'])`
            // di tabel anak akan ditolak MySQL.
            $table->unique(['school_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('schools');
    }
};
