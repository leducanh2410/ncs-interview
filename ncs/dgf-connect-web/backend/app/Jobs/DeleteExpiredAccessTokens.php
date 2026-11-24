<?php

namespace App\Jobs;

use App\Models\AccessToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteExpiredAccessTokens implements ShouldQueue
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
        $deletedCount = AccessToken::query()->where('expires_at', '<', now())->delete();

        if ($deletedCount > 0) {
            Log::info("DeleteExpiredAccessTokens: Deleted {$deletedCount} expired access tokens");
        }
    }
}
