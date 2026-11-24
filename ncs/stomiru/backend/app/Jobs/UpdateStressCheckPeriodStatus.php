<?php

namespace App\Jobs;

use App\Enums\StressCheckPeriodStatus;
use App\Models\StressCheckPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateStressCheckPeriodStatus implements ShouldQueue
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
        $today = now()->toDateString();

        StressCheckPeriod::query()
            ->where('start_date', '>', $today)
            ->update(['status' => StressCheckPeriodStatus::NotStarted]);

        StressCheckPeriod::query()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->update(['status' => StressCheckPeriodStatus::InProgress]);

        StressCheckPeriod::query()
            ->where('end_date', '<', $today)
            ->update(['status' => StressCheckPeriodStatus::Finished]);
    }
}
