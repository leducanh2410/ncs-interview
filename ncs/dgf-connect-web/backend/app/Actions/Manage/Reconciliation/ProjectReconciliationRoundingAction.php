<?php

namespace App\Actions\Manage\Reconciliation;

use App\Enums\ReconciliationDecision;
use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use App\Models\ReconciliationRow;
use App\Services\Reconciliation\ReconciliationFieldCompare;
use Illuminate\Support\Carbon;

class ProjectReconciliationRoundingAction
{
    /**
     * @return array<string, string>
     */
    public function __invoke(ReconciliationRow $row, CheckIn $checkIn): array
    {
        if ($row->skipped || $row->external_check_in === null || $row->external_check_out === null) {
            return [];
        }

        $amazonDecision = ReconciliationDecision::Amazon->value;
        $amazon = [
            'check_in_time' => Carbon::parse($row->external_check_in),
            'check_out_time' => Carbon::parse($row->external_check_out),
        ];
        $dgfPaid = [
            'check_in_time' => ReconciliationFieldCompare::paidCheckIn($checkIn),
            'check_out_time' => ReconciliationFieldCompare::paidCheckOut($checkIn),
        ];

        $warnings = [];
        foreach ($amazon as $field => $amazonValue) {
            if (ReconciliationFieldCompare::timesMatch($amazonValue->toDateTimeString(), $dgfPaid[$field])) {
                continue;
            }

            $projected = $this->projectPaidTimes(
                $row,
                $checkIn,
                $field === 'check_in_time' || $row->decision_check_in === $amazonDecision,
                $field === 'check_out_time' || $row->decision_check_out === $amazonDecision
            );
            if ($projected === null) {
                continue;
            }

            if (
                !ReconciliationFieldCompare::timesMatch(
                    $projected[$field]->toDateTimeString(),
                    $amazonValue->toDateTimeString()
                )
            ) {
                $warnings[$field] = $projected[$field]->toDateTimeString();
            }
        }

        return $warnings;
    }

    /**
     * @return array{check_in_time: Carbon, check_out_time: Carbon}|null
     */
    public function projectPaidTimes(
        ReconciliationRow $row,
        CheckIn $checkIn,
        bool $useAmazonCheckIn,
        bool $useAmazonCheckOut
    ): ?array {
        $start = $useAmazonCheckIn
            ? $this->importedTime($row->external_check_in, $checkIn->check_in_time)
            : $checkIn->check_in_time;
        $end = $useAmazonCheckOut
            ? $this->importedTime($row->external_check_out, $checkIn->check_out_time)
            : $checkIn->check_out_time;
        if ($start === null || $end === null) {
            return null;
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);
        if ($end->lte($start)) {
            return null;
        }

        $checkIn->loadMissing(['shift.workplaceShift', 'shiftUser']);
        $rounded = WorkHelper::roundCheckTimes(
            $checkIn->shift,
            $start,
            $end,
            (bool) $checkIn->shiftUser?->is_overtime_allowed
        );

        return [
            'check_in_time' => $rounded['check_in_time'],
            'check_out_time' => $rounded['check_out_time'],
        ];
    }

    private function importedTime(mixed $amazon, mixed $dgf): mixed
    {
        if ($amazon === null || $dgf === null) {
            return $amazon;
        }

        return ReconciliationFieldCompare::timesMatch(
            Carbon::parse($amazon)->toDateTimeString(),
            Carbon::parse($dgf)->toDateTimeString()
        )
            ? $dgf
            : $amazon;
    }
}
