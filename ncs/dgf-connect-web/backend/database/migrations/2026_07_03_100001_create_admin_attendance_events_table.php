<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('admin_attendance_sessions')->cascadeOnDelete();
            $table->string('type'); // AdminAttendanceEventType: check_in | check_out
            $table->dateTime('event_time');
            $table->string('coordinate');
            $table->timestamps();

            $table->index(['session_id', 'event_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_attendance_events');
    }
};
