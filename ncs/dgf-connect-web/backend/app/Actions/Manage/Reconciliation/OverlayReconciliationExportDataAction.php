<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\Common\CheckIn\CalculateAttendanceDeviationAction;
use App\Actions\Manage\WorkHistory\BuildAttendanceExportColumnsAction;
use App\Enums\ReconciliationDecision;
use App\Models\CheckIn;
use App\Models\ReconciliationRow;
use App\Services\Reconciliation\ReconciliationFieldCompare;
use Illuminate\Support\Carbon;

class OverlayReconciliationExportDataAction
{
    public function __construct(
        private readonly ProjectReconciliationRoundingAction $projectRounding,
        private readonly BuildAttendanceExportColumnsAction $buildAttendanceColumns,
        private readonly CalculateAttendanceDeviationAction $calculateDeviation
    ) {}

    /**
     * Field list copied from WorkHistoryService::buildDetailExportData so this feature
     * does not edit that method (DGFC_132 conflict surface). Always includes check_in_id.
     *
     * @return array<string, mixed>
     */
    public function mapCheckIn(CheckIn $checkIn): array
    {
        $checkIn->loadMissing(['shift.workplace', 'shift.workplaceShift', 'user', 'shiftUser']);
        $workplace = $checkIn->shift->workplace;
        $shift = $checkIn->shift;
        $workplaceShift = $checkIn->shift->workplaceShift;
        $shiftStart = $workplaceShift->start_time;
        $shiftEnd = $workplaceShift->end_time;
        if ($shiftEnd < $shiftStart) {
            $tmp = Carbon::parse($shiftEnd);
            $hour = $tmp->hour + 24;
            $minute = $tmp->format('i');
            $shiftEnd = "{$hour}:{$minute}";
        }

        $breakTime = ReconciliationFieldCompare::effectiveBreakMinutes($checkIn);

        return [
            'check_in_id' => $checkIn->id,
            'shift_user_id' => $checkIn->shift_user_id,
            'workplace_name' => $workplace->name,
            'workplace_salary_per_hour' => $workplace->getAttribute('salary_per_hour'),
            'date' => Carbon::parse($shift->date)->format('Y/n/j'),
            'shift_time' => "{$shiftStart}-{$shiftEnd}",
            'staff_no' => $checkIn->user->staff_no,
            'name' => $checkIn->user->short_name,
            'name_kana' => $checkIn->user->name_kana,
            'check_in_time' => $this->toDayFraction($checkIn->check_in_time),
            'check_out_time' => $this->toDayFraction($checkIn->check_out_time),
            'check_in_time_rounded' => $this->toDayFraction($checkIn->check_in_time_rounded),
            'check_out_time_rounded' => $this->toDayFraction($checkIn->check_out_time_rounded),
            'break_time' => (string) $breakTime,
            'work_minutes' => $checkIn->work_minutes,
            'work_minutes_normal' => $checkIn->work_minutes_normal,
            'work_minutes_night' => $checkIn->work_minutes_night,
            'work_minutes_overtime' => $checkIn->work_minutes_overtime,
            'work_minutes_night_overtime' => $checkIn->work_minutes_night_overtime,
            ...($this->buildAttendanceColumns)($checkIn, (bool) $checkIn->shiftUser?->is_absent),
        ];
    }

    public function overlay(array $item, ReconciliationRow $row, CheckIn $checkIn): array
    {
        $useAmazonCheckIn = $row->decision_check_in === ReconciliationDecision::Amazon->value;
        $useAmazonCheckOut = $row->decision_check_out === ReconciliationDecision::Amazon->value;
        if ($useAmazonCheckIn) {
            $item['check_in_time'] = $this->toDayFraction($row->external_check_in?->toDateTimeString());
        }
        if ($useAmazonCheckOut) {
            $item['check_out_time'] = $this->toDayFraction($row->external_check_out?->toDateTimeString());
        }
        if ($useAmazonCheckIn || $useAmazonCheckOut) {
            $projected = $this->projectRounding->projectPaidTimes(
                $row,
                $checkIn,
                $useAmazonCheckIn,
                $useAmazonCheckOut
            );
            $item['check_in_time_rounded'] = $this->toDayFraction($projected['check_in_time'] ?? null);
            $item['check_out_time_rounded'] = $this->toDayFraction($projected['check_out_time'] ?? null);
            $item = [
                ...$item,
                ...$this->overlaidAttendanceColumns($row, $checkIn, $useAmazonCheckIn, $useAmazonCheckOut),
            ];
        }
        if ($row->decision_break === ReconciliationDecision::Amazon->value) {
            $item['break_time'] = (string) $row->external_break_minutes;
        }

        $item['check_in_id'] = $checkIn->id;

        return $item;
    }

    public function toDayFraction(mixed $time): ?float
    {
        if ($time === null || $time === '') {
            return null;
        }
        $parsed = Carbon::parse($time);

        return ($parsed->hour * 60 + $parsed->minute) / 1440.0;
    }

    /**
     * @return array{attendance_status: string, attendance_reason: string}
     */
    private function overlaidAttendanceColumns(
        ReconciliationRow $row,
        CheckIn $checkIn,
        bool $useAmazonCheckIn,
        bool $useAmazonCheckOut
    ): array {
        $overlaid = $checkIn->replicate();
        if ($useAmazonCheckIn && $row->external_check_in) {
            $overlaid->check_in_time = $row->external_check_in->toDateTimeString();
        }
        if ($useAmazonCheckOut && $row->external_check_out) {
            $overlaid->check_out_time = $row->external_check_out->toDateTimeString();
        }
        $overlaid->fill(($this->calculateDeviation)($overlaid));
        if ($overlaid->late_minutes === 0) {
            $overlaid->late_reason = null;
        }
        if ($overlaid->early_minutes === 0) {
            $overlaid->early_reason = null;
        }

        return ($this->buildAttendanceColumns)($overlaid, (bool) $checkIn->shiftUser?->is_absent);
    }
}
