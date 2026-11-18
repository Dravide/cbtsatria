<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leisure_game_scores', function (Blueprint $table) {
            $table->string('game_type', 32)->default('lompat')->after('exam_participant_id');

            $table->index(['school_id', 'game_type', 'class_id', 'score'], 'luang_game_kelas');
            $table->index(['school_id', 'game_type', 'student_id', 'score'], 'luang_game_siswa');
        });
    }

    public function down(): void
    {
        Schema::table('leisure_game_scores', function (Blueprint $table) {
            $table->dropIndex('luang_game_kelas');
            $table->dropIndex('luang_game_siswa');
            $table->dropColumn('game_type');
        });
    }
};
