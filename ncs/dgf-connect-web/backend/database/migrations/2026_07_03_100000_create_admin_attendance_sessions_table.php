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
        Schema::create('admin_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('first_check_in_at');
            $table->dateTime('last_check_out_at')->nullable();
            $table->integer('work_minutes')->nullable();
            $table->timestamps();

            // One session per admin per workplace per day
            $table->unique(['user_id', 'workplace_id', 'work_date']);
            $table->index(['user_id', 'work_date']);
            $table->index(['workplace_id', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_attendance_sessions');
    }
};
