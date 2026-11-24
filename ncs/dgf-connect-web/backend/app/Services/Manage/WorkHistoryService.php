<?php

namespace App\Services\Manage;

use App\Actions\Manage\WorkHistory\BuildAttendanceExportColumnsAction;
use App\Enums\PermissionName;
use App\Exports\WorkHistoryExport;
use App\Helpers\WorkHelper;
use App\Jobs\CollectWorkTime;
use App\Jobs\CreateShiftReport;
use App\Jobs\UpdateUserSalary;
use App\Models\CheckIn;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkTimeReport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class WorkHistoryService
{
    private const WORK_MINUTE_FIELDS = [
        'work_minutes',
        'work_minutes_normal',
        'work_minutes_night',
        'work_minutes_overtime',
        'work_minutes_night_overtime',
    ];

    public function getWorkHistory(array $conditions, User $user): LengthAwarePaginator
    {
        $query = CheckIn::getFilteredQuery($conditions, [
            'shift.workplace',
            'shift.workplaceShift',
            'user',
            'shiftUser:id,is_absent',
            'latestEditRequest.items',
            'latestEditRequest.processedBy',
        ]);

        $checkIns = $query
            ->applyWorkplaceFilter($user, PermissionName::WorkHistoryList->value)
            ->paginate($conditions['page_size'] ?? null);

        $checkIns->through(function (CheckIn $checkIn) {
            return $this->applyAbsentPresentation($checkIn);
        });

        return $checkIns;
    }

    public function getMonthWorkHistory(array $conditions, User $user): LengthAwarePaginator
    {
        $workplaceId = $conditions['workplace_id'] ?? null;
        unset($conditions['workplace_id']);

        $query = WorkTimeReport::getFilteredQuery($conditions, ['user', 'details.workplace'])->whereHas(
            'details',
            function ($query) use ($workplaceId) {
                if ($workplaceId) {
                    $query->where('workplace_id', $workplaceId);
                }
            }
        );

        return $query
            ->applyWorkplaceFilter($user, PermissionName::WorkHistoryList->value, 'details')
            ->paginate($conditions['page_size'] ?? null);
    }

    public function applyValues(CheckIn $checkIn, array $data, int $attributedUserId): void
    {
        $checkInTime = Carbon::parse("{$data['check_in_date']} {$data['check_in_time']}");
        $checkOutTime = null;
        if (isset($data['check_out_date'], $data['check_out_time'])) {
            $checkOutTime = Carbon::parse("{$data['check_out_date']} {$data['check_out_time']}");
        }
        $clearCheckOut = $data['clear_check_out'] ?? false;
        $breakMinutes = $data['break_minutes'] ?? null;

        $data = [
            'check_in_time' => $checkInTime,
        ];

        if ($breakMinutes !== null) {
            $data['break_minutes'] = $breakMinutes;
        }

        if ($checkOutTime) {
            $checkIn->loadMissing('shift.workplace', 'shift.workplaceShift');
            $minutes = WorkHelper::calculateWorkHour(
                $checkIn->shift,
                $checkInTime,
                $checkOutTime,
                $checkIn->shiftUser->is_overtime_allowed,
                $breakMinutes
            );

            $data['work_minutes'] = $minutes['total'];
            $data['work_minutes_normal'] = $minutes['normal'];
            $data['work_minutes_night'] = $minutes['night'];
            $data['work_minutes_overtime'] = $minutes['overtime'];
            $data['work_minutes_night_overtime'] = $minutes['night_overtime'];
            $data['check_out_time'] = $checkOutTime;
            $data['check_in_time_rounded'] = $minutes['check_in_time'];
            $data['check_out_time_rounded'] = $minutes['check_out_time'];
        } elseif ($clearCheckOut) {
            // Restore the "just checked in, not yet checked out" state: the rounded times and
            // work-minute breakdown are only ever populated together with a check-out time.
            $data['check_out_time'] = null;
            $data['check_in_time_rounded'] = null;
            $data['check_out_time_rounded'] = null;
            $data['work_minutes'] = 0;
            $data['work_minutes_normal'] = 0;
            $data['work_minutes_night'] = 0;
            $data['work_minutes_overtime'] = 0;
            $data['work_minutes_night_overtime'] = 0;
        }

        $needReport = false;
        $oldCheckInTime = Carbon::parse($checkIn->check_in_time);
        $oldCheckOutTime = $checkIn->check_out_time ? Carbon::parse($checkIn->check_out_time) : null;
        $oldBreakMinutes = $checkIn->break_minutes;
        if ($oldCheckInTime->ne($checkInTime)) {
            $data['checked_in_by'] = $attributedUserId;
            $needReport = true;
        }
        if (!$oldCheckOutTime) {
            if ($checkOutTime) {
                $data['checked_out_by'] = $attributedUserId;
                $needReport = true;
            }
        } elseif ($checkOutTime && $oldCheckOutTime->ne($checkOutTime)) {
            $data['checked_out_by'] = $attributedUserId;
            $needReport = true;
        } elseif ($clearCheckOut) {
            $data['checked_out_by'] = null;
            $needReport = true;
        }

        // Break minutes feed straight into the work-minutes recalculation above, so a
        // break-only change on a record that has a check-out still needs the downstream
        // report/salary/work-time jobs re-run even though neither timestamp itself moved.
        if ($breakMinutes !== null && $checkOutTime && (int) $oldBreakMinutes !== (int) $breakMinutes) {
            $needReport = true;
        }

        $checkIn->update($data);

        if ($needReport) {
            // Dispatched after the enclosing approval transaction commits: applyValues() runs
            // inside DB::transaction() in ApproveWorkHistoryEditRequestAction, and the queue
            // connection has after_commit=false, so an immediate dispatch could let a worker
            // read this row before the update is committed (and, in the bulk-resolve case, get
            // orphaned entirely if a later item in the batch fails and rolls the transaction
            // back). afterCommit() dispatches right away when there is no open transaction, so
            // this stays correct for callers outside a transaction too.
            dispatch(new CreateShiftReport($checkIn->shift_user_id))->afterCommit();
            dispatch(new UpdateUserSalary($checkIn->user_id, mb_substr($checkIn->shift->date, 0, 7)))->afterCommit();
            dispatch(new CollectWorkTime($checkInTime->startOfDay(), $checkIn->user_id))->afterCommit();
        }
    }

    /**
     * Distinct workplace ids that have exportable check-ins for the given filters,
     * ordered by workplace name. Lets the export job process one workplace at a
     * time instead of holding a whole month of rows in memory.
     *
     * @return array<int, int>
     */
    public function getDetailExportWorkplaceIds(array $conditions, User $user): array
    {
        return CheckIn::getFilteredQuery($conditions)
            ->applyWorkplaceFilter($user, PermissionName::WorkHistoryExport->value)
            ->whereNotNull('check_out_time')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->join('workplaces', 'workplaces.id', '=', 'shifts.workplace_id')
            ->select('shifts.workplace_id', 'workplaces.name')
            ->distinct()
            ->orderBy('workplaces.name')
            ->pluck('shifts.workplace_id')
            ->all();
    }

    /**
     * Build the detail export rows, grouped by workplace id and sorted for output.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function buildDetailExportData(array $conditions, User $user): array
    {
        $exportData = [];
        $buildAttendanceColumns = app(BuildAttendanceExportColumnsAction::class);
        CheckIn::getFilteredQuery($conditions, [
            'shift.workplace',
            'shift.workplaceShift',
            'user',
            'shiftUser:id,is_absent',
        ])
            ->applyWorkplaceFilter($user, PermissionName::WorkHistoryExport->value)
            ->whereNotNull('check_out_time')
            ->lazy()
            ->each(function ($checkIn) use (&$exportData, $buildAttendanceColumns) {
                $checkIn = $this->applyAbsentPresentation($checkIn);
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

                $breakTime = $workplaceShift->break_duration;
                if ($shift->break_minutes !== null) {
                    $breakTime = $shift->break_minutes;
                }
                // A per-check-in break override (set via the work-history edit-request flow)
                // takes priority over the shift-level default, matching the same resolution
                // order WorkHelper::calculateWorkHour already uses when applying an approved
                // edit - otherwise this column would silently keep showing the shift default
                // even after an individual correction was approved.
                if ($checkIn->break_minutes !== null) {
                    $breakTime = $checkIn->break_minutes;
                }
                // Pre-existing edge case: when none of the three sources above has a value (no
                // workplace-shift break duration, no shift-level override, no check-in-level
                // override), $breakTime stays null and (string) null is '', which throws a
                // TypeError on the '' / 1440 division in WorkHistoryDetailExport. 0 is the
                // correct "no break" value here, consistent with WorkHelper's own treatment of a
                // null break duration.
                $breakTime ??= 0;

                $exportData[$workplace->id][] = [
                    'check_in_id' => $checkIn->id,
                    'shift_user_id' => $checkIn->shift_user_id,
                    'workplace_name' => $workplace->name,
                    'workplace_salary_per_hour' => $workplace->salary_per_hour,
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
                    ...$buildAttendanceColumns($checkIn, (bool) $checkIn->is_absent),
                ];
            });

        foreach ($exportData as $id => $data) {
            // Sort by date -> shift_time -> shift_user_id
            usort($exportData[$id], function ($a, $b) {
                $dateA = Carbon::parse($a['date']);
                $dateB = Carbon::parse($b['date']);
                if ($dateA->ne($dateB)) {
                    return $dateA->lt($dateB) ? -1 : 1;
                }

                if ($a['shift_time'] !== $b['shift_time']) {
                    return $a['shift_time'] <=> $b['shift_time'];
                }

                return $a['shift_user_id'] <=> $b['shift_user_id'];
            });
        }

        return $exportData;
    }

    /**
     * Convert a time-of-day (ignoring its date component) to a fraction of a day, e.g. `09:00`
     * -> 0.375, for cells formatted with Excel's `[H]:mm` number format. Shared by the detail
     * export and, for bulk-import reconciliation, by comparing this against a live value
     * recomputed the same way.
     */
    public function toDayFraction(?string $time): ?float
    {
        if ($time === null) {
            return null;
        }
        $parsed = Carbon::parse($time);

        return ($parsed->hour * 60 + $parsed->minute) / 1440.0;
    }

    public function exportMonthlyReport(array $conditions, User $user): ?array
    {
        $workplaceId = $conditions['workplace_id'] ?? null;
        unset($conditions['workplace_id']);

        $records = WorkTimeReport::getFilteredQuery($conditions, ['user'])
            ->whereHas('details', function ($query) use ($workplaceId) {
                if ($workplaceId) {
                    $query->where('workplace_id', $workplaceId);
                }
            })
            ->applyWorkplaceFilter($user, PermissionName::WorkHistoryList->value, 'details')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $month = Carbon::parse($conditions['month']);
        $filename = 'work_hours_report_' . $month->format('Ym');
        $collection = collect();

        foreach ($records as $record) {
            if (!$workplaceId) {
                $collection->push([
                    'staff_no' => $record->user->staff_no,
                    'user' => $record->user->short_name,
                    'work_days' => $record->work_days,
                    'work_hours' => $record->work_hours / 24,
                    'work_hours_normal' => $record->work_hours_normal / 24,
                    'work_hours_up_1' => $record->work_hours_up_1 / 24,
                    'work_hours_night_overtime' => $record->work_hours_night_overtime / 24,
                ]);
            }

            $workplaceRecord = $record->details->firstWhere('workplace_id', $workplaceId);
            if (!$workplaceRecord) {
                continue;
            }
            $collection->push([
                'staff_no' => $record->user->staff_no,
                'user' => $record->user->short_name,
                'work_days' => $workplaceRecord->work_days,
                'work_hours' => $workplaceRecord->work_hours / 24,
                'work_hours_normal' => $workplaceRecord->work_hours_normal / 24,
                'work_hours_up_1' => $workplaceRecord->work_hours_up_1 / 24,
                'work_hours_night_overtime' => $workplaceRecord->work_hours_night_overtime / 24,
            ]);
        }

        $total = $collection->count();

        $collection->push(['']);
        $collection->push(['Tháng', $month->format('m/Y')]);
        $collection->push(['Ngày xuất báo cáo', now()->format('d/m/Y H:i:s')]);
        if ($workplaceId) {
            $workplace = Workplace::query()->find($workplaceId);
            if ($workplace) {
                $collection->push(['Xưởng', $workplace->name]);
            }
        }

        return [
            'filename' => "{$filename}.xlsx",
            'report' => new WorkHistoryExport($collection, $total),
        ];
    }

    private function applyAbsentPresentation(CheckIn $checkIn): CheckIn
    {
        $isAbsent = (bool) $checkIn->shiftUser?->is_absent;
        $checkIn->setAttribute('is_absent', $isAbsent);
        $checkIn->unsetRelation('shiftUser');

        if ($isAbsent) {
            foreach (self::WORK_MINUTE_FIELDS as $field) {
                $checkIn->setAttribute($field, 0);
            }
        }

        return $checkIn;
    }
}
