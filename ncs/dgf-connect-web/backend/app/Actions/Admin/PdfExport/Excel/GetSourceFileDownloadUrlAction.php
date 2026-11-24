<?php

namespace App\Actions\Admin\PdfExport\Excel;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Helpers\UrlHelper;
use App\Models\ExportBatch;
use Illuminate\Support\Facades\Storage;

class GetSourceFileDownloadUrlAction
{
    public function __invoke(ExportBatch $batch): ActionResult
    {
        $path = $batch->sourceFilePath();

        if (!$batch->isPdf() || !$batch->isFromExcel() || $path === null || !Storage::exists($path)) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        return new ActionResult(
            data: [
                'download_url' => UrlHelper::getStorageUrl($path),
                'file_name' => $batch->sourceFileName(),
            ]
        );
    }
}
