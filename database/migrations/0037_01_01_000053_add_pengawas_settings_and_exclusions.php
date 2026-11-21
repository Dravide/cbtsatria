<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_events', function (Blueprint $table) {
            $table->json('pengawas_settings')->nullable()->after('violation_thresholds');
        });

        Schema::table('event_committee_members', function (Blueprint $table) {
            $table->unsignedSmallInteger('maks_mengawas')->nullable()->after('position');
        });

        Schema::create('supervisor_session_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('school_events')->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 120)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'exam_session_id', 'supervisor_id'], 'sse_event_session_supervisor_uq');
            $table->index(['school_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_session_exclusions');

        Schema::table('event_committee_members', function (Blueprint $table) {
            $table->dropColumn('maks_mengawas');
        });

        Schema::table('school_events', function (Blueprint $table) {
            $table->dropColumn('pengawas_settings');
        });
    }
};
