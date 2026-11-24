<?php

namespace App\Jobs;

use App\Enums\VerificationStatus;
use App\Models\CheckInVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteOldCheckInVerifications implements ShouldQueue
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
        $retentionDays = config('work.check_in.verification_retention_days', 30);

        $verifications = CheckInVerification::query()
            ->where('status', VerificationStatus::Verified->value)
            ->where('is_spoofed', false)
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->get();

        $deletedCount = 0;
        $deletedFilesCount = 0;

        foreach ($verifications as $verification) {
            // Delete the image file from storage
            if ($verification->image_path && Storage::exists($verification->image_path)) {
                Storage::delete($verification->image_path);
                $deletedFilesCount++;
            }

            // Delete the verification record
            $verification->delete();
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            Log::info(
                "DeleteOldCheckInVerifications: Deleted {$deletedCount} verifications and {$deletedFilesCount} files (retention: {$retentionDays} days)"
            );
        }
    }
}
