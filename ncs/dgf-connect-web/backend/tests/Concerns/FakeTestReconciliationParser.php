<?php

namespace Tests\Concerns;

use App\Services\Reconciliation\CanonicalRow;
use App\Services\Reconciliation\ReconciliationSourceParser;
use Illuminate\Support\Carbon;

final class FakeTestReconciliationParser implements ReconciliationSourceParser
{
    public function __construct(
        private readonly string $externalName = 'Fake User',
        private readonly string $workDate = '2026-08-25',
        private readonly string $checkIn = '2026-08-25 20:19:00',
        private readonly string $checkOut = '2026-08-26 06:03:00',
        private readonly int $breakMinutes = 60
    ) {}

    public function source(): string
    {
        return 'fake_test';
    }

    public function parse(string $absolutePath): array
    {
        return [
            'company_label' => 'fake',
            'punch_rows' => [
                new CanonicalRow(
                    $this->externalName,
                    $this->workDate,
                    Carbon::parse($this->checkIn),
                    Carbon::parse($this->checkOut),
                    $this->breakMinutes,
                    ['source' => 'fake_test']
                ),
            ],
            'skipped_non_punch_count' => 0,
        ];
    }
}
