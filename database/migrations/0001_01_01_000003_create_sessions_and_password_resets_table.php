<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi dan reset password - dipisah dari tabel users.
 *
 * ---------------------------------------------------------------------
 * password_reset_tokens dikunci pada username, bukan email
 * ---------------------------------------------------------------------
 * Bawaan Laravel mengunci tabel ini pada `email` sebagai primary key.
 * Siswa di sistem ini tidak selalu punya email, jadi kunci itu akan
 * membuat mereka mustahil direset lewat jalur mana pun. Username dipakai
 * sebagai gantinya - ia unik global, jadi ia kunci yang sah.
 *
 * Fase 1 tidak mengirim surel reset: laman /lupa-password hanya
 * mengarahkan ke Admin sekolah (wireframe plate 02). Reset oleh Admin
 * dibangun di Fase 2 pada laman Pengguna. Tabelnya tetap dibuat sekarang
 * supaya jalur itu punya tempat mendarat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('username')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
