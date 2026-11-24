<?php

namespace App\Jobs;

use App\Enums\UserPaperItemFolder;
use App\Models\UserPaperItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteOldUserPaperItems implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $cutoff = now()->subYear();

        $items = UserPaperItem::query()
            ->where('folder', UserPaperItemFolder::Deleted->value)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->get();

        $deletedCount = 0;
        $deletedFilesCount = 0;

        foreach ($items as $item) {
            if ($item->file_path && Storage::exists($item->file_path)) {
                Storage::delete($item->file_path);
                $deletedFilesCount++;
            }

            $item->delete();
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            Log::info(
                "DeleteOldUserPaperItems: Deleted {$deletedCount} papers and {$deletedFilesCount} files older than 1 year"
            );
        }
    }
}
