<?php

namespace App\Actions\Admin\PdfExport;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ExportBatchItemStatus;
use App\Enums\ExportBatchStatus;
use App\Helpers\UrlHelper;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use Illuminate\Support\Facades\Storage;

class GetDownloadUrlAction
{
    public function getItemUrl(ExportBatchItem $item): ActionResult
    {
        if ($item->status !== ExportBatchItemStatus::Completed || !$item->file_path) {
            return new ActionResult(
                error: ErrorCode::NotFound,
                status: 404,
                data: ['message' => 'PDF file not available.']
            );
        }

        $downloadUrl = UrlHelper::getStorageUrl($item->file_path);

        return new ActionResult(data: ['download_url' => $downloadUrl]);
    }

    public function getBatchUrl(ExportBatch $batch): ActionResult
    {
        $zipFilePaths = collect($batch->zip_file_paths ?? [])
            ->filter()
            ->filter(fn(string $path) => Storage::exists($path))
            ->values()
            ->all();

        if ($batch->status !== ExportBatchStatus::Completed || empty($zipFilePaths)) {
            return new ActionResult(
                error: ErrorCode::NotFound,
                status: 404,
                data: ['message' => 'Batch not ready for download.']
            );
        }

        $completedCount = $batch->items()->completed()->count();

        if ($completedCount === 0) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'Batch has no completed items.']
            );
        }

        $downloadUrls = array_map(UrlHelper::getStorageUrl(...), $zipFilePaths);

        return new ActionResult(
            data: [
                'download_url' => $downloadUrls[0],
                'download_urls' => $downloadUrls,
            ]
        );
    }
}
