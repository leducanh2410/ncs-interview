<?php

namespace App\Jobs;

use App\Models\AdminPaperUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Purges admin scan drafts abandoned for more than a day, with their images.
 *
 * A draft is an unsubmitted capture: the admin walked away mid-flow, or the app lost the
 * id. Nothing downstream reads one, so leaving it behind only keeps a copy of somebody's
 * identity document on disk for no purpose.
 */
class DeleteStaleAdminPaperUploadDrafts implements ShouldQueue
{
    use Queueable;

    private const STALE_AFTER_HOURS = 24;

    public function handle(): void
    {
        $cutoff = now()->subHours(self::STALE_AFTER_HOURS);
        $deleted = 0;

        AdminPaperUpload::query()
            ->whereNull('submitted_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function (Collection $drafts) use (&$deleted) {
                $paths = $drafts->pluck('file_path')->filter()->all();

                $deleted += AdminPaperUpload::query()->whereIn('id', $drafts->pluck('id'))->delete();

                if ($paths !== []) {
                    Storage::delete($paths);
                }
            });

        if ($deleted > 0) {
            Log::info("DeleteStaleAdminPaperUploadDrafts: Deleted {$deleted} abandoned admin scan drafts");
        }
    }
}
