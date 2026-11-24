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
        Schema::create('work_time_report_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_time_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplace_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('work_days');
            $table->float('work_hours');
            $table->float('work_hours_normal');
            $table->float('work_hours_night');
            $table->float('work_hours_overtime');
            $table->float('work_hours_night_overtime');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_time_report_details');
    }
};
