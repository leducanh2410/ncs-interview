<?php

namespace App\Jobs;

use App\Models\CheckIn;
use App\Models\WorkTimeReport;
use App\Models\WorkTimeReportDetail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CollectWorkTime implements ShouldQueue
{
    use Queueable;

    private const EMPTY_WORK_TIME = [
        'work_days' => 0,
        'work_minutes' => 0,
        'work_minutes_normal' => 0,
        'work_minutes_night' => 0,
        'work_minutes_overtime' => 0,
        'work_minutes_night_overtime' => 0,
    ];

    private readonly ?Carbon $from;

    private readonly ?int $userId;

    private readonly ?string $month;

    /**
     * @param string|null $month 'Y-m' of the only month that changed. Skips the scan for
     *                           recently touched check-ins, which would otherwise re-aggregate
     *                           every later month of that user too. Requires $userId.
     */
    public function __construct(?Carbon $from = null, ?int $userId = null, ?string $month = null)
    {
        $this->from = $from;
        $this->userId = $userId;
        $this->month = $month;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->month && $this->userId) {
            Log::info("[CollectWorkTime] Started. Collect month {$this->month} of user {$this->userId}");
            $months = [$this->month => [$this->userId]];
        } else {
            $months = $this->discoverMonths();

            if ($months === null) {
                return;
            }
        }

        foreach ($months as $month => $userIds) {
            Log::info("[CollectWorkTime] Collect month: {$month}");
            Log::info('[CollectWorkTime] Total users: ' . count($userIds));
            $startDate = Carbon::parse($month)->startOfMonth();
            $endDate = Carbon::parse($month)->endOfMonth();
            $groupByUser = CheckIn::query()
                ->select('check_ins.*', 'shifts.workplace_id', 'shifts.date')
                ->join('shift_users', 'shift_users.id', '=', 'check_ins.shift_user_id')
                ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
                ->whereBetween('shifts.date', [$startDate, $endDate])
                ->shiftNotAbsent()
                ->whereNotNull('check_ins.work_minutes')
                ->whereIn('check_ins.user_id', array_values(array_unique($userIds)))
                ->lazy()
                ->groupBy('user_id');

            $collectedUserIds = [];
            foreach ($groupByUser as $userId => $checkIns) {
                $collectedUserIds[] = $userId;
                $report = WorkTimeReport::query()->updateOrCreate(
                    ['user_id' => $userId, 'month' => $month],
                    [
                        'work_days' => $checkIns->pluck('date')->unique()->count(),
                        'work_minutes' => $checkIns->sum('work_minutes'),
                        'work_minutes_normal' => $checkIns->sum('work_minutes_normal'),
                        'work_minutes_night' => $checkIns->sum('work_minutes_night'),
                        'work_minutes_overtime' => $checkIns->sum('work_minutes_overtime'),
                        'work_minutes_night_overtime' => $checkIns->sum('work_minutes_night_overtime'),
                    ]
                );

                $groupByWorkplace = $checkIns->groupBy('workplace_id');
                foreach ($groupByWorkplace as $workplaceId => $workplaceCheckIns) {
                    WorkTimeReportDetail::query()->updateOrCreate(
                        ['work_time_report_id' => $report->id, 'workplace_id' => $workplaceId],
                        [
                            'work_days' => $workplaceCheckIns->unique()->count(),
                            'work_minutes' => $workplaceCheckIns->sum('work_minutes'),
                            'work_minutes_normal' => $workplaceCheckIns->sum('work_minutes_normal'),
                            'work_minutes_night' => $workplaceCheckIns->sum('work_minutes_night'),
                            'work_minutes_overtime' => $workplaceCheckIns->sum('work_minutes_overtime'),
                            'work_minutes_night_overtime' => $workplaceCheckIns->sum('work_minutes_night_overtime'),
                        ]
                    );
                }

                $this->resetReportDetails($report->id, $groupByWorkplace->keys()->filter()->values()->toArray());
            }

            $this->resetReportsWithoutWorkTime($month, array_values(array_unique($userIds)), $collectedUserIds);
        }

        Log::info('[CollectWorkTime] Ended');
    }

    /**
     * Find which months need aggregating from the check-ins touched since $from.
     *
     * @return array<string, int[]>|null null when there is nothing to do
     */
    private function discoverMonths(): ?array
    {
        $from = $this->from ?? now()->subDay()->startOfDay();

        Log::info("[CollectWorkTime] Started. Collect from: {$from->format('Y-m-d H:i:s')}");
        if ($this->userId) {
            Log::info("[CollectWorkTime] Collect user: {$this->userId}");
        }

        $checkIns = CheckIn::query()
            ->select('check_ins.*', 'shifts.workplace_id', 'shifts.date')
            ->join('shift_users', 'shift_users.id', '=', 'check_ins.shift_user_id')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->where('check_ins.updated_at', '>=', $from)
            ->whereNotNull('check_ins.work_minutes')
            ->when($this->userId, function ($query) {
                $query->where('check_ins.user_id', $this->userId);
            })
            ->lazy();

        if ($checkIns->isEmpty()) {
            Log::info('[CollectWorkTime] Ended: No check-ins found');

            return null;
        }

        $months = [];
        foreach ($checkIns as $checkIn) {
            $month = Carbon::parse($checkIn->date)->format('Y-m');
            $months[$month][] = $checkIn->user_id;
        }

        Log::info("[CollectWorkTime] Found {$checkIns->count()} check-ins");

        return $months;
    }

    /**
     * Users whose check-ins all stopped counting for this month (e.g. every shift got
     * marked as absent) never reach the loop above, so their existing report would keep
     * its stale totals. Zero those reports and their per-workplace details instead.
     *
     * @param int[] $requestedUserIds
     * @param int[] $collectedUserIds
     */
    private function resetReportsWithoutWorkTime(string $month, array $requestedUserIds, array $collectedUserIds): void
    {
        $staleUserIds = array_diff($requestedUserIds, $collectedUserIds);

        if (empty($staleUserIds)) {
            return;
        }

        $reports = WorkTimeReport::query()->whereIn('user_id', $staleUserIds)->where('month', $month)->get();

        foreach ($reports as $report) {
            $report->update(self::EMPTY_WORK_TIME);
            $this->resetReportDetails($report->id, []);
        }
    }

    /**
     * Zero every detail row of a report that no longer has counted work time, keeping
     * the rows themselves so historical workplaces stay visible.
     *
     * @param int[] $keepWorkplaceIds workplaces that were just recalculated
     */
    private function resetReportDetails(int $reportId, array $keepWorkplaceIds): void
    {
        WorkTimeReportDetail::query()
            ->where('work_time_report_id', $reportId)
            ->when($keepWorkplaceIds, function ($query) use ($keepWorkplaceIds) {
                $query->whereNotIn('workplace_id', $keepWorkplaceIds);
            })
            ->update(self::EMPTY_WORK_TIME);
    }
}
