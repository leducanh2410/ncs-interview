<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationRow;
use Illuminate\Support\Carbon;

final readonly class CanonicalRow
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $externalName,
        public string $workDate,
        public Carbon $checkIn,
        public Carbon $checkOut,
        public int $breakMinutes,
        public array $raw,
        public ?string $staffNo = null
    ) {}

    public static function fromRow(ReconciliationRow $row): self
    {
        $raw = $row->raw ?? [];

        return new self(
            externalName: $row->external_name,
            workDate: Carbon::parse($row->work_date)->toDateString(),
            checkIn: Carbon::parse($row->external_check_in),
            checkOut: Carbon::parse($row->external_check_out),
            breakMinutes: $row->external_break_minutes,
            raw: $raw,
            staffNo: isset($raw['staff_no']) ? (string) $raw['staff_no'] : null
        );
    }

    public static function duplicateKeyFor(string $externalName, ?string $staffNo, string $workDate): string
    {
        $normalizedStaffNo = ReconciliationNameNormalizer::staffNo($staffNo);
        $identity =
            $normalizedStaffNo !== ''
                ? 'staff:' . $normalizedStaffNo
                : ReconciliationNameNormalizer::compact($externalName);

        return $identity . '|' . $workDate;
    }

    public function duplicateKey(): string
    {
        return self::duplicateKeyFor($this->externalName, $this->staffNo, $this->workDate);
    }
}
