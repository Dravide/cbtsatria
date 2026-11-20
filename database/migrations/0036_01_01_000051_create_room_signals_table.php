<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('exam_session_room_id')->constrained('exam_session_rooms')->cascadeOnDelete();
            $table->string('jenis', 32);
            $table->string('catatan', 255)->nullable();
            $table->foreignId('pelapor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pelapor_nama', 120)->nullable();
            $table->string('status', 20)->default('aktif');
            $table->foreignId('ditangani_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditangani_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['exam_session_room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_signals');
    }
};
