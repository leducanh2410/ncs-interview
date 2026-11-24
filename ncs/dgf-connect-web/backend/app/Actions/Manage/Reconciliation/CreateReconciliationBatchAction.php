<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ReconciliationBatchStatus;
use App\Models\ReconciliationBatch;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationFormatException;
use App\Services\Reconciliation\ReconciliationParserRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateReconciliationBatchAction
{
    public function __construct(
        private readonly ReconciliationParserRegistry $registry,
        private readonly PersistReconciliationRowsAction $persistRows,
        private readonly RecalculateReconciliationBatchCountsAction $recalculateCounts
    ) {}

    public function __invoke(User $actor, string $source, UploadedFile $file): ActionResult
    {
        if (!$this->registry->has($source)) {
            return new ActionResult(
                error: ErrorCode::ReconciliationUnknownSource,
                status: 422,
                data: [
                    'source' => $source,
                ]
            );
        }

        $tempPath = $file->getRealPath();
        if ($tempPath === false) {
            return new ActionResult(error: ErrorCode::ReconciliationInvalidFormat, status: 422);
        }

        try {
            $parsed = $this->registry->get($source)->parse($tempPath);
        } catch (ReconciliationFormatException $exception) {
            $error =
                $exception->reason === ErrorCode::ReconciliationUnknownSource->value
                    ? ErrorCode::ReconciliationUnknownSource
                    : ErrorCode::ReconciliationInvalidFormat;

            return new ActionResult(
                error: $error,
                status: 422,
                data: [
                    'reason' => $exception->reason,
                    'details' => $exception->details,
                    'detected_source' =>
                        $exception->reason === 'header_mismatch' ? $this->registry->detect($tempPath, $source) : null,
                ]
            );
        }

        if ($parsed['punch_rows'] === []) {
            return new ActionResult(error: ErrorCode::ReconciliationNoPunchRows, status: 422);
        }

        $storedPath = null;
        try {
            $batch = DB::transaction(function () use ($actor, $source, $file, $parsed, &$storedPath) {
                $batch = ReconciliationBatch::create([
                    'source' => $source,
                    'original_filename' => $file->getClientOriginalName(),
                    'original_file_path' => '',
                    'company_label' => $parsed['company_label'],
                    'status' => ReconciliationBatchStatus::Draft->value,
                    'skipped_non_punch_count' => $parsed['skipped_non_punch_count'],
                    'uploaded_by' => $actor->id,
                ]);

                $storedPath = $file->storeAs('reconciliation/' . $batch->id, 'original.xlsx');
                if ($storedPath === false) {
                    throw new \RuntimeException('Failed to store reconciliation original file.');
                }
                $batch->original_file_path = $storedPath;
                $batch->save();

                ($this->persistRows)($batch, $parsed['punch_rows'], $actor);
                ($this->recalculateCounts)($batch->refresh());

                return $batch;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::delete($storedPath);
            }

            throw $exception;
        }

        $batch->load(['rows.user', 'rows.checkIn.shift.workplace', 'uploadedBy', 'confirmedBy', 'exportedBy']);

        return new ActionResult(data: $this->serialize($batch, $actor), status: 201);
    }

    public function serialize(ReconciliationBatch $batch, User $actor, bool $includeRows = true): array
    {
        return app(SerializeReconciliationBatchAction::class)($batch, $actor, $includeRows);
    }
}
