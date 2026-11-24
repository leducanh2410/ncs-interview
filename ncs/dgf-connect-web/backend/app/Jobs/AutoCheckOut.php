<?php

namespace App\Jobs;

use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCheckOut implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            $checkIns = CheckIn::query()
                ->with('shift.workplaceShift', 'shift.workplace', 'shiftUser')
                ->whereNull('check_out_time')
                ->whereDoesntHave('pendingCheckOutEditRequests')
                ->get();
            $now = now();
            foreach ($checkIns as $checkIn) {
                $shift = $checkIn->shift;
                [$start, $end] = $shift->getTimeRange();
                $diff = $end->diffInHours($now);
                if ($diff >= 2) {
                    $minutes = WorkHelper::calculateWorkHour(
                        $checkIn->shift,
                        Carbon::parse($checkIn->check_in_time),
                        $end,
                        $checkIn->shiftUser->is_overtime_allowed,
                        $checkIn->break_minutes
                    );
                    $data = [
                        'check_in_time_rounded' => $minutes['check_in_time'],
                        'check_out_time' => $end,
                        'check_out_time_rounded' => $minutes['check_out_time'],
                        'checked_out_by' => 0, // System
                        'work_minutes' => $minutes['total'],
                        'work_minutes_normal' => $minutes['normal'],
                        'work_minutes_night' => $minutes['night'],
                        'work_minutes_overtime' => $minutes['overtime'],
                        'work_minutes_night_overtime' => $minutes['night_overtime'],
                    ];
                    $checkIn->update($data);
                    Log::info("AutoCheckOut: ID = {$checkIn->id}, time = {$end->format('Y-m-d H:i:s')}");

                    // Don't need to collect work time because it will run tomorrow
                }
            }
        });
    }
}
