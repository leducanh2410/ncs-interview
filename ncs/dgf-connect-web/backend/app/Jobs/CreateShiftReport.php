<?php

namespace App\Jobs;

use App\Enums\ShiftUserStatus;
use App\Models\ShiftUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateShiftReport implements ShouldQueue
{
    use Queueable;

    private readonly ?int $shiftUserId;

    /**
     * Create a new job instance.
     */
    public function __construct(?int $shiftUserId = null)
    {
        $this->shiftUserId = $shiftUserId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('CreateShiftReport started');

        DB::transaction(function () {
            $query = ShiftUser::query()->with('shift.workplaceShift', 'checkIn', 'report');

            $shiftUsers = $this->shiftUserId
                ? $query->where('id', $this->shiftUserId)->get()
                : $query->where('status', ShiftUserStatus::Approved)->where('is_reported', false)->get();

            $reportedIds = [];

            /** @var ShiftUser $shiftUser */
            foreach ($shiftUsers as $shiftUser) {
                $shift = $shiftUser->shift;
                [, $end] = $shift->getTimeRange();
                if ($end->isAfter(now())) {
                    continue;
                }

                $reportedIds[] = $shiftUser->id;
                $report = [
                    'user_id' => $shiftUser->user_id,
                    'date' => $shift->date,
                ];

                if (!$shiftUser->checkIn) {
                    $report['is_absent'] = true;
                    $shiftUser->report()->create($report);

                    continue;
                }

                $report['late_minutes'] = $shiftUser->checkIn->late_minutes ?: null;
                $report['early_minutes'] = $shiftUser->checkIn->early_minutes ?: null;

                if ($shiftUser->report) {
                    $shiftUser->report()->update($report);
                } else {
                    $shiftUser->report()->create($report);
                }
            }

            ShiftUser::query()
                ->whereIn('id', $reportedIds)
                ->update(['is_reported' => true]);
            $count = count($reportedIds);

            Log::info("CreateShiftReport finished. {$count} reports created.");
        });
    }
}
