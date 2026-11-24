<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Csv\CreateCsvExportAction;
use App\Enums\ErrorCode;
use App\Enums\ExportBatchStatus;
use App\Helpers\ResponseHelper;
use App\Helpers\UrlHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CsvExport\CreateCsvExportRequest;
use App\Models\ExportBatch;
use App\Services\Csv\CsvPropertyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CsvExportController extends Controller
{
    public function __construct(
        private readonly CreateCsvExportAction $createCsvExport,
        private readonly CsvPropertyRegistry $registry
    ) {}

    public function properties(): JsonResponse
    {
        $properties = array_map(
            fn($definition): array => [
                'key' => $definition->key,
                'label_ja' => $definition->labelJa,
            ],
            array_values($this->registry->all())
        );

        return ResponseHelper::success($properties);
    }

    public function store(CreateCsvExportRequest $request): JsonResponse
    {
        $batch = $this->createCsvExport->execute(Auth::id(), $request->validated());

        $batch->load('exporter:id,first_name,last_name,short_name');

        return ResponseHelper::success($batch, 202);
    }

    public function download(ExportBatch $batch): JsonResponse
    {
        if (!$batch->isCsv()) {
            return ResponseHelper::notFound(ErrorCode::NotFound, ['message' => 'CSV export batch not found.']);
        }

        if (
            $batch->status !== ExportBatchStatus::Completed ||
            !$batch->file_path ||
            !Storage::exists($batch->file_path)
        ) {
            return ResponseHelper::notFound(ErrorCode::NotFound, ['message' => 'CSV file not available.']);
        }

        return ResponseHelper::success([
            'download_url' => UrlHelper::getStorageUrl($batch->file_path),
        ]);
    }
}
