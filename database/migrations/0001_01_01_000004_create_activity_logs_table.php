<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log aktivitas sekolah.
 *
 * Append-only dan sengaja tanpa updated_at: tidak ada yang boleh mengubah
 * sebuah catatan setelah ia ditulis, dan laman Log aktivitas (wireframe
 * plate 24) tidak punya tombol hapus. Membuka token, mengoreksi presensi,
 * dan membuka kunci ujian semuanya dapat dipersoalkan setelah ujian selesai,
 * jadi catatannya harus tetap ada meskipun Admin-nya sudah berganti.
 *
 * user_id nullable: tidak setiap aksi punya aktor manusia. Sistem sendiri
 * menulis baris - mis. "Mengunci ujian" pada siswa yang melewati ambang
 * pelanggaran. Menghapus user tidak menghapus jejaknya, karena itu
 * nullOnDelete, bukan cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Kata kerja dalam bahasa manusia, mis. "Membuka token sesi".
            $table->string('action');

            // Objek yang dikenai aksi. entity_type memakai nama pendek kelas
            // (mis. "ExamSession") supaya log tidak terikat pada namespace.
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->json('metadata_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['school_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
