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
        Schema::table('check_ins', function (Blueprint $table) {
            $table->renameColumn('work_hours', 'work_minutes');
            $table->renameColumn('work_hours_normal', 'work_minutes_normal');
            $table->renameColumn('work_hours_night', 'work_minutes_night');
            $table->renameColumn('work_hours_overtime', 'work_minutes_overtime');
            $table->renameColumn('work_hours_night_overtime', 'work_minutes_night_overtime');
        });

        Schema::table('work_time_reports', function (Blueprint $table) {
            $table->renameColumn('work_hours', 'work_minutes');
            $table->renameColumn('work_hours_normal', 'work_minutes_normal');
            $table->renameColumn('work_hours_night', 'work_minutes_night');
            $table->renameColumn('work_hours_overtime', 'work_minutes_overtime');
            $table->renameColumn('work_hours_night_overtime', 'work_minutes_night_overtime');
        });

        Schema::table('work_time_report_details', function (Blueprint $table) {
            $table->renameColumn('work_hours', 'work_minutes');
            $table->renameColumn('work_hours_normal', 'work_minutes_normal');
            $table->renameColumn('work_hours_night', 'work_minutes_night');
            $table->renameColumn('work_hours_overtime', 'work_minutes_overtime');
            $table->renameColumn('work_hours_night_overtime', 'work_minutes_night_overtime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->renameColumn('work_minutes', 'work_hours');
            $table->renameColumn('work_minutes_normal', 'work_hours_normal');
            $table->renameColumn('work_minutes_night', 'work_hours_night');
            $table->renameColumn('work_minutes_overtime', 'work_hours_overtime');
            $table->renameColumn('work_minutes_night_overtime', 'work_hours_night_overtime');
        });

        Schema::table('work_time_reports', function (Blueprint $table) {
            $table->renameColumn('work_minutes', 'work_hours');
            $table->renameColumn('work_minutes_normal', 'work_hours_normal');
            $table->renameColumn('work_minutes_night', 'work_hours_night');
            $table->renameColumn('work_minutes_overtime', 'work_hours_overtime');
            $table->renameColumn('work_minutes_night_overtime', 'work_hours_night_overtime');
        });

        Schema::table('work_time_report_details', function (Blueprint $table) {
            $table->renameColumn('work_minutes', 'work_hours');
            $table->renameColumn('work_minutes_normal', 'work_hours_normal');
            $table->renameColumn('work_minutes_night', 'work_hours_night');
            $table->renameColumn('work_minutes_overtime', 'work_hours_overtime');
            $table->renameColumn('work_minutes_night_overtime', 'work_hours_night_overtime');
        });
    }
};
