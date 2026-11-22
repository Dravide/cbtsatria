<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('school_events')->cascadeOnDelete();
            $table->unsignedBigInteger('exam_session_id')->nullable();
            $table->unsignedBigInteger('exam_session_room_id')->nullable();
            $table->string('jenis', 40);
            $table->string('tingkat', 16)->default('warn');
            $table->string('judul', 120);
            $table->string('pesan', 255);
            $table->string('konteks_label', 60)->nullable();
            $table->string('tautan', 200)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'read_at', 'id']);
            $table->index(['school_id', 'event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notifications');
    }
};
