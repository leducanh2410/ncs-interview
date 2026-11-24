<?php

namespace App\Jobs;

use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteOldNotifications implements ShouldQueue
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
        $retentionDays = config('notification.retention_days', 7);

        $deletedCount = Notification::query()
            ->whereNotNull('user_id')
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', now()->subDays($retentionDays))
            ->delete();

        if ($deletedCount > 0) {
            Log::info(
                "DeleteOldNotifications: Deleted {$deletedCount} old notifications (retention: {$retentionDays} days)"
            );
        }
    }
}
