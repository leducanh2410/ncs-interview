<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportBatchType;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StartShiftOrderImportAction
{
    use ReadsShiftOrderWorkbook;

    public const STORAGE_DIRECTORY = 'imports/shift-order';

    public function __construct(
        private readonly PrepareShiftOrderPlanAction $preparePlan,
        private readonly BuildShiftOrderPreviewAction $buildPreview
    ) {}

    public function __invoke(User $user, UploadedFile $file, ?string $month): ActionResult
    {
        try {
            $workbook = $this->readWorkbook($file);
        } catch (Throwable $exception) {
            Log::error($exception);

            return new ActionResult(error: ErrorCode::ShiftOrderFileInvalidFormat, status: 422);
        }

        $prepared = ($this->preparePlan)($user, $workbook, $month);
        if ($prepared->getError() !== null) {
            return $prepared;
        }

        $data = $prepared->getData();
        $filePath = Storage::putFile(self::STORAGE_DIRECTORY, $file);
        if ($filePath === false) {
            return new ActionResult(error: ErrorCode::InternalServerError, status: 500);
        }

        $batch = ImportBatch::query()->create([
            'type' => ImportBatchType::ShiftOrder,
            'status' => ImportBatchStatus::Pending,
            'imported_by' => $user->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'meta' => [
                'month' => $data['month'],
                'detected_month' => $data['detected_month'],
                'sheet_name' => $data['sheet_name'],
                'plan' => $data['plan'],
            ],
        ]);

        return new ActionResult(
            data: [
                'batch' => $batch,
                'month' => $data['month'],
                'detected_month' => $data['detected_month'],
                'preview' => ($this->buildPreview)($data['plan']),
            ]
        );
    }
}
