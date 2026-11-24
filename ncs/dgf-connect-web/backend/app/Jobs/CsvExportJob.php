<?php

namespace App\Jobs;

use App\Enums\Encoding;
use App\Enums\ExportBatchStatus;
use App\Models\ExportBatch;
use App\Models\User;
use App\Services\Csv\CsvPropertyRegistry;
use App\Services\Csv\CsvPropertyResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Bom;
use League\Csv\Writer;
use Throwable;

class CsvExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(private readonly int $batchId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(CsvPropertyRegistry $registry, CsvPropertyResolver $resolver): void
    {
        $batch = ExportBatch::query()->find($this->batchId);

        if (!$batch || !$batch->isCsv()) {
            Log::error('CsvExportJob: CSV batch not found', [
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        $batch->update([
            'status' => ExportBatchStatus::Processing,
            'error_message' => null,
        ]);

        $meta = $batch->meta ?? [];
        $exportKeys = $this->resolveExportKeys($meta, $registry);
        $encoding =
            isset($meta['encoding']) && in_array($meta['encoding'], Encoding::values(), true)
                ? $meta['encoding']
                : null;
        $tmpPath = $this->createTempPath($batch);

        try {
            $this->writeCsv($tmpPath, $exportKeys, $registry, $resolver, $meta);

            if ($encoding === Encoding::ShiftJIS->value) {
                $this->convertToShiftJis($tmpPath);
            }

            $filePath = $this->buildFilePath($batch);
            $stream = fopen($tmpPath, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Failed to open generated CSV for upload.');
            }

            try {
                if (!Storage::put($filePath, $stream)) {
                    throw new \RuntimeException('Failed to upload generated CSV.');
                }
            } finally {
                fclose($stream);
            }

            $batch->update([
                'status' => ExportBatchStatus::Completed,
                'file_path' => $filePath,
                'csv_completed_count' => $batch->csv_total_count,
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
        Log::error('CsvExportJob: job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception?->getMessage(),
        ]);

        $batch = ExportBatch::query()->find($this->batchId);

        if (!$batch) {
            return;
        }

        if ($batch->file_path) {
            Storage::delete($batch->file_path);
        }

        $batch->update([
            'status' => ExportBatchStatus::Failed,
            'file_path' => null,
            'csv_completed_count' => 0,
            'csv_failed_count' => $batch->csv_total_count,
            'error_message' => $exception?->getMessage() ?? 'Unknown error',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private function resolveExportKeys(array $meta, CsvPropertyRegistry $registry): array
    {
        $keys = array_values(array_filter($meta['export_keys'] ?? [], 'is_string'));
        $unknownKeys = $registry->validateKeys($keys);

        if (empty($keys) || !empty($unknownKeys)) {
            throw new \InvalidArgumentException('CSV export batch contains invalid property keys.');
        }

        return $keys;
    }

    /**
     * @param  array<int, string>  $exportKeys
     * @param  array<string, mixed>  $meta
     */
    private function writeCsv(
        string $tmpPath,
        array $exportKeys,
        CsvPropertyRegistry $registry,
        CsvPropertyResolver $resolver,
        array $meta
    ): void {
        file_put_contents($tmpPath, Bom::Utf8->value);

        $writer = Writer::createFromPath($tmpPath, 'a+');
        $writer->setOutputBOM(Bom::Utf8);
        $writer->setNewline("\r\n");
        $writer->insertOne($this->buildHeaderRow($exportKeys, $registry));

        $this->userQuery($meta)
            ->with('info')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($writer, $resolver, $exportKeys): void {
                foreach ($users as $user) {
                    $writer->insertOne(
                        array_map(fn(string $key): string => $resolver->resolve($user, $key), $exportKeys)
                    );
                }
            });
    }

    /**
     * @param  array<int, string>  $exportKeys
     * @return array<int, string>
     */
    private function buildHeaderRow(array $exportKeys, CsvPropertyRegistry $registry): array
    {
        return array_map(function (string $key) use ($registry): string {
            $definition = $registry->get($key);

            if ($definition === null) {
                throw new \InvalidArgumentException("Unknown CSV property key: {$key}");
            }

            return $definition->labelJa;
        }, $exportKeys);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return Builder<User>
     */
    private function userQuery(array $meta): Builder
    {
        if (!($meta['select_all'] ?? false)) {
            $userIds = array_values(array_filter($meta['user_ids'] ?? [], 'is_numeric'));

            return User::query()->whereIn('id', $userIds);
        }

        $filters = is_array($meta['filters'] ?? null) ? $meta['filters'] : [];

        if (!isset($filters['is_disabled'])) {
            $filters['is_disabled'] = false;
        }

        return User::getFilteredQuery($filters);
    }

    private function createTempPath(ExportBatch $batch): string
    {
        $dir = storage_path('app/tmp/csv-exports');

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create CSV temp directory: {$dir}");
        }

        return $dir . "/{$batch->id}.csv";
    }

    private function buildFilePath(ExportBatch $batch): string
    {
        return sprintf('csv-exports/%d/users_export_%s.csv', $batch->id, now()->format('Ymd_His'));
    }

    private function convertToShiftJis(string $tmpPath): void
    {
        $content = file_get_contents($tmpPath);

        if ($content === false) {
            throw new \RuntimeException('Failed to read generated CSV for encoding conversion.');
        }

        $bomLength = mb_strlen(Bom::Utf8->value);

        if (str_starts_with($content, Bom::Utf8->value)) {
            $content = mb_substr($content, $bomLength);
        }

        $shiftJisContent = mb_convert_encoding($content, 'SJIS-win', 'UTF-8');

        if ($shiftJisContent === false) {
            throw new \RuntimeException('Failed to convert CSV to Shift-JIS encoding.');
        }

        file_put_contents($tmpPath, $shiftJisContent);
    }
}
