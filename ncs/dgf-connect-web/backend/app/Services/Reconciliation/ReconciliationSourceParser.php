<?php

namespace App\Services\Reconciliation;

interface ReconciliationSourceParser
{
    public function source(): string;

    /**
     * @return array{
     *     company_label: ?string,
     *     punch_rows: list<CanonicalRow>,
     *     skipped_non_punch_count: int
     * }
     *
     * @throws ReconciliationFormatException
     */
    public function parse(string $absolutePath): array;
}
