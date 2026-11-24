<?php

namespace App\Jobs;

use App\Actions\Admin\PdfTemplate\GeneratePdfAction;
use App\Enums\ExportBatchItemStatus;
use App\Enums\ExportBatchStatus;
use App\Models\ExportBatch;
use App\Models\ExportBatchItem;
use App\Services\Admin\PdfTemplate\PdfTemplateLockService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class ProcessExportChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        private readonly int $batchId,
        private readonly int $offset = 0,
        private readonly ?int $chunkSize = null
    ) {}

    public function handle(GeneratePdfAction $generatePdf, PdfTemplateLockService $lockService): void
    {
        $batch = ExportBatch::with('pdfTemplate.fields')->find($this->batchId);

        if (!$batch || !$batch->pdfTemplate) {
            Log::error('ProcessExportChunkJob: batch or template not found', [
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        if ($batch->status === ExportBatchStatus::Pending) {
            $batch->update(['status' => ExportBatchStatus::Processing]);
        }

        $chunkSize = $this->resolveChunkSize();

        $items = ExportBatchItem::where('export_batch_id', $this->batchId)
            ->with('user')
            ->pending()
            ->orderBy('id')
            ->limit($chunkSize)
            ->get();

        if ($items->isEmpty()) {
            $this->finalizeBatch($batch, $lockService);

            return;
        }

        $template = $batch->pdfTemplate;
        $year = now()->format('Y');

        foreach ($items as $item) {
            try {
                $lockService->refresh($batch->pdf_template_id, $batch->exported_by);

                $user = $item->user;

                if (!$user) {
                    $item->update([
                        'status' => ExportBatchItemStatus::Failed,
                        'error_message' => 'User not found',
                    ]);

                    continue;
                }

                $pdfContent = $generatePdf($template, $user);

                $filePath = "pdf/{$template->id}/{$year}/{$batch->id}/{$user->id}.pdf";
                $fileName = "{$template->name}_{$user->short_name}.pdf";

                Storage::put($filePath, $pdfContent);

                $item->update([
                    'status' => ExportBatchItemStatus::Completed,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                ]);
            } catch (Exception $e) {
                Log::error('ProcessExportChunkJob: item generation failed', [
                    'batch_id' => $this->batchId,
                    'item_id' => $item->id,
                    'user_id' => $item->user_id,
                    'error' => $e->getMessage(),
                ]);

                $item->update([
                    'status' => ExportBatchItemStatus::Failed,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        $remainingCount = ExportBatchItem::where('export_batch_id', $this->batchId)->pending()->count();

        if ($remainingCount > 0) {
            dispatch(new self($this->batchId, $this->offset + $chunkSize, $chunkSize));
        } else {
            $this->finalizeBatch($batch, $lockService);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessExportChunkJob: job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception?->getMessage(),
        ]);

        ExportBatchItem::where('export_batch_id', $this->batchId)
            ->pending()
            ->update([
                'status' => ExportBatchItemStatus::Failed,
                'error_message' => 'Job failed: ' . ($exception?->getMessage() ?? 'Unknown error'),
            ]);

        $batch = ExportBatch::find($this->batchId);

        if ($batch) {
            $completedCount = ExportBatchItem::where('export_batch_id', $this->batchId)->completed()->count();

            $status = $completedCount > 0 ? ExportBatchStatus::Completed : ExportBatchStatus::Failed;

            $batch->update(['status' => $status]);

            $lockService = app(PdfTemplateLockService::class);
            $lockService->release($batch->pdf_template_id, $batch->exported_by);
        }
    }

    private function resolveChunkSize(): int
    {
        return max((int) ($this->chunkSize ?? config('pdf_export.chunk_size', 50)), 1);
    }

    private function finalizeBatch(ExportBatch $batch, PdfTemplateLockService $lockService): void
    {
        $completedItems = ExportBatchItem::where('export_batch_id', $batch->id)->completed()->get();

        $failedCount = ExportBatchItem::where('export_batch_id', $batch->id)->failed()->count();

        $totalCount = ExportBatchItem::where('export_batch_id', $batch->id)->count();

        $zipFilePaths = [];

        if ($completedItems->isNotEmpty()) {
            $zipFilePaths = $this->createZipFiles($batch, $completedItems);
        }

        $hasZipFiles = !empty($zipFilePaths);
        $status =
            $failedCount === $totalCount || ($completedItems->isNotEmpty() && !$hasZipFiles)
                ? ExportBatchStatus::Failed
                : ExportBatchStatus::Completed;

        $batch->update([
            'status' => $status,
            'zip_file_paths' => $hasZipFiles ? $zipFilePaths : null,
        ]);

        $lockService->release($batch->pdf_template_id, $batch->exported_by);

        Log::info('ProcessExportChunkJob: batch finalized', [
            'batch_id' => $batch->id,
            'status' => $status->value,
            'completed' => $completedItems->count(),
            'failed' => $failedCount,
        ]);
    }

    private function createZipFiles(ExportBatch $batch, Collection $completedItems): array
    {
        $groups = [];
        $currentGroup = [];
        $currentGroupSize = 0;
        $maxZipSizeBytes = max((int) config('pdf_export.max_zip_size_bytes', 500 * 1024 * 1024), 1);
        $maxZipFileCount = max((int) config('pdf_export.max_zip_file_count', 250), 1);

        foreach ($completedItems as $item) {
            if (!$item->file_path || !Storage::exists($item->file_path)) {
                Log::warning('ProcessExportChunkJob: completed item file missing during zip creation', [
                    'batch_id' => $batch->id,
                    'item_id' => $item->id,
                    'file_path' => $item->file_path,
                ]);

                continue;
            }

            $fileSize = $this->getFileSize($item->file_path);
            $shouldStartNewGroup =
                !empty($currentGroup) &&
                (count($currentGroup) >= $maxZipFileCount ||
                    ($fileSize > 0 && $currentGroupSize + $fileSize > $maxZipSizeBytes));

            if ($shouldStartNewGroup) {
                $groups[] = $currentGroup;
                $currentGroup = [];
                $currentGroupSize = 0;
            }

            $currentGroup[] = $item;
            $currentGroupSize += max($fileSize, 0);
        }

        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        if (empty($groups)) {
            return [];
        }

        $zipPaths = [];
        $groupCount = count($groups);

        foreach ($groups as $index => $group) {
            $zipPath = $this->buildZipPath($batch, $index + 1, $groupCount);

            if (!$this->createZipArchive($batch, collect($group), $zipPath)) {
                if (!empty($zipPaths)) {
                    Storage::delete($zipPaths);
                }

                return [];
            }

            $zipPaths[] = $zipPath;
        }

        return $zipPaths;
    }

    private function buildZipPath(ExportBatch $batch, int $partNumber, int $totalParts): string
    {
        $template = $batch->pdfTemplate;
        $year = now()->format('Y');
        $basePath = "pdf/{$template->id}/{$year}/{$batch->id}";

        if ($totalParts === 1) {
            return "{$basePath}/all.zip";
        }

        return sprintf('%s/all_part_%02d.zip', $basePath, $partNumber);
    }

    private function createZipArchive(ExportBatch $batch, Collection $items, string $zipPath): bool
    {
        $tempBasePath = tempnam(sys_get_temp_dir(), 'export_');

        if ($tempBasePath === false) {
            Log::error('ProcessExportChunkJob: failed to allocate temp file for zip creation', [
                'batch_id' => $batch->id,
                'zip_path' => $zipPath,
            ]);

            return false;
        }

        @unlink($tempBasePath);
        $tempZipPath = $tempBasePath . '.zip';
        $tempSourcePaths = [];
        $addedEntries = 0;

        try {
            $zip = new ZipArchive();

            if ($zip->open($tempZipPath, ZipArchive::CREATE) !== true) {
                Log::error('ProcessExportChunkJob: failed to create zip file', [
                    'batch_id' => $batch->id,
                    'zip_path' => $zipPath,
                ]);

                return false;
            }

            $usedEntryNames = [];

            foreach ($items as $item) {
                $sourcePath = $this->resolveLocalZipSourcePath($item->file_path, $tempSourcePaths);

                if (!$sourcePath) {
                    Log::warning('ProcessExportChunkJob: unable to prepare file for zip archive', [
                        'batch_id' => $batch->id,
                        'item_id' => $item->id,
                        'file_path' => $item->file_path,
                        'zip_path' => $zipPath,
                    ]);

                    continue;
                }

                $entryName = $this->resolveArchiveEntryName(
                    $item->file_name ?? basename($item->file_path),
                    $usedEntryNames
                );

                if (!$zip->addFile($sourcePath, $entryName)) {
                    Log::error('ProcessExportChunkJob: failed to add file to zip archive', [
                        'batch_id' => $batch->id,
                        'item_id' => $item->id,
                        'file_path' => $item->file_path,
                        'zip_path' => $zipPath,
                    ]);

                    continue;
                }

                $addedEntries++;
            }

            $zip->close();

            if ($addedEntries === 0) {
                Log::error('ProcessExportChunkJob: zip archive has no entries', [
                    'batch_id' => $batch->id,
                    'zip_path' => $zipPath,
                ]);

                return false;
            }

            $stream = fopen($tempZipPath, 'rb');

            if ($stream === false) {
                Log::error('ProcessExportChunkJob: failed to open temp zip stream', [
                    'batch_id' => $batch->id,
                    'zip_path' => $zipPath,
                ]);

                return false;
            }

            try {
                return Storage::put($zipPath, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            foreach ($tempSourcePaths as $tempSourcePath) {
                if (file_exists($tempSourcePath)) {
                    unlink($tempSourcePath);
                }
            }

            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }
        }
    }

    private function getFileSize(string $filePath): int
    {
        try {
            return (int) Storage::size($filePath);
        } catch (Throwable) {
            return 0;
        }
    }

    private function resolveLocalZipSourcePath(?string $filePath, array &$tempSourcePaths): ?string
    {
        if (!$filePath || !Storage::exists($filePath)) {
            return null;
        }

        try {
            $localPath = Storage::path($filePath);

            if (is_file($localPath)) {
                return $localPath;
            }
        } catch (Throwable) {
            // Fall back to a streamed temp file when the storage driver has no direct local path.
        }

        $readStream = Storage::readStream($filePath);

        if ($readStream === false) {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'export_src_');

        if ($tempPath === false) {
            fclose($readStream);

            return null;
        }

        $writeStream = fopen($tempPath, 'wb');

        if ($writeStream === false) {
            fclose($readStream);
            @unlink($tempPath);

            return null;
        }

        try {
            stream_copy_to_stream($readStream, $writeStream);
        } finally {
            fclose($readStream);
            fclose($writeStream);
        }

        $tempSourcePaths[] = $tempPath;

        return $tempPath;
    }

    private function resolveArchiveEntryName(string $fileName, array &$usedEntryNames): string
    {
        if (!isset($usedEntryNames[$fileName])) {
            $usedEntryNames[$fileName] = 0;

            return $fileName;
        }

        $usedEntryNames[$fileName]++;
        $suffix = '_' . $usedEntryNames[$fileName];
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);

        return $extension === '' ? $baseName . $suffix : $baseName . $suffix . '.' . $extension;
    }
}
