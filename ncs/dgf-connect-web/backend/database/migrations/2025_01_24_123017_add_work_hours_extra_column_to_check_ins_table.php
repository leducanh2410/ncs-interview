<?php

use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->float('work_hours_normal')->after('work_hours')->default(0);
            $table->float('work_hours_night')->after('work_hours_normal')->default(0);
            $table->float('work_hours_overtime')->after('work_hours_night')->default(0);
            $table->float('work_hours_night_overtime')->after('work_hours_overtime')->default(0);
        });

        $checkIns = CheckIn::query()->with('shift.workplaceShift')->get();
        foreach ($checkIns as $checkIn) {
            $startTime = Carbon::parse($checkIn->check_in_time);
            $endTime = Carbon::parse($checkIn->check_out_time);
            $data = WorkHelper::calculateWorkHour($checkIn->shift, $startTime, $endTime);
            $checkIn->update([
                'work_hours' => $data['total'],
                'work_hours_normal' => $data['normal'],
                'work_hours_night' => $data['night'],
                'work_hours_overtime' => $data['overtime'],
                'work_hours_night_overtime' => $data['night_overtime'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropColumn('work_hours_normal');
            $table->dropColumn('work_hours_night');
            $table->dropColumn('work_hours_overtime');
            $table->dropColumn('work_hours_night_overtime');
        });
    }
};
