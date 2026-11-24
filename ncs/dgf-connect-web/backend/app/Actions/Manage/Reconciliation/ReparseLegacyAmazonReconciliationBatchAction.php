<?php

namespace App\Actions\Manage\Reconciliation;

use App\Enums\ReconciliationSource;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Services\Reconciliation\AmazonKadouParser;
use App\Services\Reconciliation\CanonicalRow;
use App\Services\Reconciliation\ReconciliationFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReparseLegacyAmazonReconciliationBatchAction
{
    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_REPARSED = 'reparsed';

    public const RESULT_DELETED = 'deleted';

    public function __construct(
        private readonly AmazonKadouParser $parser,
        private readonly PersistReconciliationRowsAction $persistRows,
        private readonly RecalculateReconciliationBatchCountsAction $recalculateCounts
    ) {}

    public function __invoke(ReconciliationBatch $batch): string
    {
        return DB::transaction(function () use ($batch) {
            $batch = ReconciliationBatch::lockById($batch->id);
            if (!$this->isLegacyAmazonDraft($batch)) {
                return self::RESULT_SKIPPED;
            }

            $parsed = $this->parseOriginal($batch);
            if ($parsed === null || $parsed['punch_rows'] === []) {
                $path = $batch->original_file_path;
                $batch->delete();
                if ($path) {
                    DB::afterCommit(fn() => Storage::delete($path));
                }

                return self::RESULT_DELETED;
            }

            $batch->rows()->delete();
            $batch->company_label = $parsed['company_label'];
            $batch->skipped_non_punch_count = $parsed['skipped_non_punch_count'];
            $batch->save();

            ($this->persistRows)($batch, $parsed['punch_rows'], $batch->uploadedBy);
            ($this->recalculateCounts)($batch->refresh());

            return self::RESULT_REPARSED;
        });
    }

    private function isLegacyAmazonDraft(ReconciliationBatch $batch): bool
    {
        if (!$batch->isDraft() || $batch->source !== ReconciliationSource::AmazonKadou->value) {
            return false;
        }

        return $batch
            ->rows()
            ->get(['id', 'raw'])
            ->contains(fn(ReconciliationRow $row) => $row->holdsLegacyPunchTimes());
    }

    /**
     * @return array{company_label: ?string, skipped_non_punch_count: int, punch_rows: list<CanonicalRow>}|null
     */
    private function parseOriginal(ReconciliationBatch $batch): ?array
    {
        if (!$batch->original_file_path || !Storage::exists($batch->original_file_path)) {
            return null;
        }

        try {
            return $this->parser->parse(Storage::path($batch->original_file_path));
        } catch (ReconciliationFormatException) {
            return null;
        }
    }
}
