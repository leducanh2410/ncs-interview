<?php

namespace App\Jobs;

use App\Enums\ExportBatchStatus;
use App\Exports\WorkHistoryDetailExport;
use App\Models\ExportBatch;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;
use ZipArchive;

class WorkHistoryExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(private readonly int $batchId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(WorkHistoryService $workHistoryService): void
    {
        $batch = ExportBatch::query()->find($this->batchId);

        if (!$batch || !$batch->isWorkHistory()) {
            Log::error('WorkHistoryExportJob: work history batch not found', [
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        $exporter = User::query()->find($batch->exported_by);

        if (!$exporter) {
            $this->markFailed($batch, 'Exporter user no longer exists.');

            return;
        }

        $batch->update([
            'status' => ExportBatchStatus::Processing,
            'csv_completed_count' => 0,
            'csv_failed_count' => 0,
            'error_message' => null,
        ]);

        $conditions = $batch->meta['conditions'] ?? [];
        $workplaceIds = $workHistoryService->getDetailExportWorkplaceIds($conditions, $exporter);

        if (empty($workplaceIds)) {
            $this->markFailed($batch, 'No work history data to export.');

            return;
        }

        $tmpPath = $this->createTempPath($batch);

        try {
            $batch->update(['csv_total_count' => count($workplaceIds)]);

            $completed = $this->writeZip($tmpPath, $workplaceIds, $conditions, $exporter, $workHistoryService, $batch);

            $filePath = $this->buildFilePath($batch);
            $stream = fopen($tmpPath, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Failed to open generated ZIP for upload.');
            }

            try {
                if (!Storage::put($filePath, $stream)) {
                    throw new \RuntimeException('Failed to upload generated ZIP.');
                }
            } finally {
                fclose($stream);
            }

            $batch->update([
                'status' => ExportBatchStatus::Completed,
                'file_path' => $filePath,
                'csv_completed_count' => $completed,
                'csv_failed_count' => 0,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            if ($batch->file_path) {
                Storage::delete($batch->file_path);
            }

            throw $exception;
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('WorkHistoryExportJob: job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception?->getMessage(),
        ]);

        $batch = ExportBatch::query()->find($this->batchId);

        if (!$batch) {
            return;
        }

        $this->markFailed($batch, $exception?->getMessage() ?? 'Unknown error');
    }

    /**
     * Builds and appends one xlsx per workplace, loading rows per workplace so
     * peak memory stays bounded by the largest workplace, not the whole export.
     *
     * @param  array<int, int>  $workplaceIds
     */
    private function writeZip(
        string $tmpPath,
        array $workplaceIds,
        array $conditions,
        User $exporter,
        WorkHistoryService $workHistoryService,
        ExportBatch $batch
    ): int {
        $zip = new ZipArchive();

        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Failed to create ZIP archive: {$tmpPath}");
        }

        $completed = 0;

        try {
            $usedNames = [];
            foreach ($workplaceIds as $workplaceId) {
                $workplaceConditions = array_merge($conditions, ['workplace_id' => $workplaceId]);
                $rows = $workHistoryService->buildDetailExportData($workplaceConditions, $exporter)[$workplaceId] ?? [];

                if (empty($rows)) {
                    continue;
                }

                $content = Excel::raw(new WorkHistoryDetailExport([$workplaceId => $rows]), ExcelFormat::XLSX);
                $entryName = $this->buildEntryName($workplaceId, $rows, $usedNames);
                if (!$zip->addFromString($entryName, $content)) {
                    throw new \RuntimeException("Failed to add {$entryName} to ZIP archive.");
                }
                unset($rows, $content);
                $completed++;
                $batch->update(['csv_completed_count' => $completed]);
            }
        } finally {
            if (!$zip->close()) {
                throw new \RuntimeException('Failed to finalize ZIP archive.');
            }
        }

        return $completed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, true>  $usedNames
     */
    private function buildEntryName(int $workplaceId, array $rows, array &$usedNames): string
    {
        $workplaceName =
            $rows[0]['workplace_name'] ?? (Workplace::query()->find($workplaceId)?->name ?? "workplace_{$workplaceId}");
        $safeName = mb_trim(preg_replace('/[\\/\\\\:*?"<>|]+/', '_', $workplaceName));

        if ($safeName === '' || isset($usedNames[$safeName])) {
            $safeName = "{$safeName}_{$workplaceId}";
        }
        $usedNames[$safeName] = true;

        return "{$safeName}.xlsx";
    }

    private function markFailed(ExportBatch $batch, string $message): void
    {
        if ($batch->file_path) {
            Storage::delete($batch->file_path);
        }

        $batch->update([
            'status' => ExportBatchStatus::Failed,
            'file_path' => null,
            'csv_completed_count' => 0,
            'csv_failed_count' => $batch->csv_total_count,
            'error_message' => $message,
        ]);
    }

    private function createTempPath(ExportBatch $batch): string
    {
        $dir = storage_path('app/tmp/work-history-exports');

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create ZIP temp directory: {$dir}");
        }

        return $dir . "/{$batch->id}.zip";
    }

    private function buildFilePath(ExportBatch $batch): string
    {
        return sprintf('work-history-exports/%d/work_hours_report_%s.zip', $batch->id, now()->format('Ymd_His'));
    }
}
