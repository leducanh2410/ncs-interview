<?php

namespace App\Actions\Csv;

use App\Enums\ExportBatchStatus;
use App\Enums\ExportBatchType;
use App\Jobs\CsvExportJob;
use App\Models\ExportBatch;
use App\Models\User;
use App\Services\Csv\CsvPropertyRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateCsvExportAction
{
    public function __construct(private readonly CsvPropertyRegistry $registry) {}

    /**
     * @param  array{
     *     selected_keys: array<int, string>,
     *     ordered_keys: array<int, string>,
     *     encoding?: string,
     *     user_ids?: array<int, int|string>,
     *     select_all?: bool,
     *     filters?: array<string, mixed>
     * }  $payload
     */
    public function execute(int $exportedBy, array $payload): ExportBatch
    {
        $selectedKeys = $this->stringValues($payload['selected_keys'] ?? []);
        $orderedKeys = $this->stringValues($payload['ordered_keys'] ?? []);

        $this->validatePropertyKeys($selectedKeys, $orderedKeys);

        $exportKeys = $this->deriveExportKeys($selectedKeys, $orderedKeys);

        if (empty($exportKeys)) {
            throw new InvalidArgumentException('At least one CSV property must be selected.');
        }

        $selectAll = (bool) ($payload['select_all'] ?? false);
        $filters = $this->resolveFilters($payload['filters'] ?? [], $selectAll);
        $userIds = $selectAll ? [] : $this->integerValues($payload['user_ids'] ?? []);
        $totalCount = $selectAll ? User::getFilteredQuery($filters)->count() : count($userIds);
        $encoding = $payload['encoding'] ?? null;

        return DB::transaction(function () use (
            $exportedBy,
            $selectedKeys,
            $orderedKeys,
            $exportKeys,
            $encoding,
            $selectAll,
            $filters,
            $userIds,
            $totalCount
        ): ExportBatch {
            $batch = ExportBatch::query()->create([
                'type' => ExportBatchType::Csv,
                'exported_by' => $exportedBy,
                'status' => ExportBatchStatus::Pending,
                'csv_total_count' => $totalCount,
                'csv_completed_count' => 0,
                'csv_failed_count' => 0,
                'meta' => [
                    'selected_keys' => $selectedKeys,
                    'ordered_keys' => $orderedKeys,
                    'export_keys' => $exportKeys,
                    'encoding' => $encoding,
                    'user_ids' => $userIds,
                    'select_all' => $selectAll,
                    'filters' => $filters,
                ],
            ]);

            dispatch(new CsvExportJob($batch->id))->afterCommit();

            return $batch;
        });
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @param  array<int, string>  $orderedKeys
     */
    private function validatePropertyKeys(array $selectedKeys, array $orderedKeys): void
    {
        $unknownKeys = array_merge(
            $this->registry->validateKeys($selectedKeys),
            $this->registry->validateKeys($orderedKeys)
        );

        if (!empty($unknownKeys)) {
            throw new InvalidArgumentException(
                'Unknown CSV property keys: ' . implode(', ', array_unique($unknownKeys))
            );
        }
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @param  array<int, string>  $orderedKeys
     * @return array<int, string>
     */
    private function deriveExportKeys(array $selectedKeys, array $orderedKeys): array
    {
        $selectedLookup = array_flip($selectedKeys);

        return array_values(array_filter($orderedKeys, fn(string $key): bool => isset($selectedLookup[$key])));
    }

    /**
     * @param  array<string, mixed>|mixed  $filters
     * @return array<string, mixed>
     */
    private function resolveFilters(mixed $filters, bool $selectAll): array
    {
        if (!is_array($filters)) {
            $filters = [];
        }

        if ($selectAll && !isset($filters['is_disabled'])) {
            $filters['is_disabled'] = false;
        }

        return $filters;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function stringValues(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function integerValues(array $values): array
    {
        return array_values(array_unique(array_map('intval', array_filter($values, 'is_numeric'))));
    }
}
