<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_participants', function (Blueprint $table) {
            $table->string('emergency_device_mode', 10)->nullable()->after('exam_session_room_id');
            $table->unsignedSmallInteger('emergency_seat_number')->nullable()->after('emergency_device_mode');
            $table->unsignedBigInteger('emergency_origin_room_id')->nullable()->after('emergency_seat_number');
            $table->string('emergency_reason', 255)->nullable()->after('emergency_origin_room_id');
            $table->timestamp('emergency_moved_at')->nullable()->after('emergency_reason');
        });
    }

    public function down(): void
    {
        Schema::table('exam_participants', function (Blueprint $table) {
            $table->dropColumn([
                'emergency_device_mode',
                'emergency_seat_number',
                'emergency_origin_room_id',
                'emergency_reason',
                'emergency_moved_at',
            ]);
        });
    }
};
