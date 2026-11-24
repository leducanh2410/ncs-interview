<?php

namespace App\Jobs;

use App\Models\ShiftPlanDraft;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Purges Shift Planner drafts that fell before the minimum planning window
 * (today + 2 days in JST) and can therefore never be finalized (requirements §4.2).
 */
class DeleteStaleShiftPlanDrafts implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $minDate = Carbon::today()->addDays(2)->format('Y-m-d');

        $deletedCount = ShiftPlanDraft::query()->where('date', '<', $minDate)->delete();

        if ($deletedCount > 0) {
            Log::info(
                "DeleteStaleShiftPlanDrafts: Deleted {$deletedCount} stale shift plan drafts (before {$minDate})"
            );
        }
    }
}
