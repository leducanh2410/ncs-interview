<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteStalePendingImportBatches implements ShouldQueue
{
    use Queueable;

    private const STALE_AFTER_HOURS = 24;

    public function handle(): void
    {
        $cutoff = now()->subHours(self::STALE_AFTER_HOURS);
        $deleted = 0;

        ImportBatch::query()
            ->where('status', ImportBatchStatus::Pending->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function (Collection $batches) use (&$deleted) {
                $paths = $batches->pluck('file_path')->filter()->all();

                $deleted += ImportBatch::query()->whereIn('id', $batches->pluck('id'))->delete();

                if ($paths !== []) {
                    Storage::delete($paths);
                }
            });

        if ($deleted > 0) {
            Log::info("DeleteStalePendingImportBatches: Deleted {$deleted} abandoned import previews");
        }
    }
}
