<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->unsignedInteger('ai_limit')->default(20)->after('address');
            $table->unsignedInteger('ai_used')->default(0)->after('ai_limit');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn(['ai_limit', 'ai_used']);
        });
    }
};
