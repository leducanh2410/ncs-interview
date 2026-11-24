<?php

namespace App\Actions\Admin\Forecast;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Helpers\WorkHelper;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Services\Common\AbsenceService;
use App\Services\Common\SimilarShiftService;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateForecastAction
{
    private const TIMEZONE = 'Asia/Tokyo';

    private const RELIABILITY_MIN_ASSIGNMENTS = 20;

    private const REQUEST_ABOVE_RECENT_AVERAGE_THRESHOLD = 1.5;

    private const SAFE_COVERAGE_THRESHOLD = 0.85;

    /**
     * Default radius, in kilometres, used to pull in nearby workplaces when estimating
     * the callable pool. Roughly an hour of local transport, so workers registered at a
     * workplace this close are treated as realistically callable. Overridable per request
     * via the `radius_km` input.
     */
    private const DEFAULT_NEARBY_RADIUS_KM = 10.0;

    /**
     * Share of the window's shifts a worker must have checked in to before they count
     * as a full (weight 1.0) member of the active pool. At 0.5, anyone who worked at
     * least half of the shifts is treated as reliably callable; people below that
     * contribute proportional partial credit. Raising it shrinks the pool toward only
     * the most regular workers; lowering it widens it toward a plain distinct headcount.
     * Placeholder value pending calibration against actual achievable approvals.
     */
    private const POOL_FULL_CREDIT_SHIFT_RATIO = 0.5;

    /**
     * Window, in days, over which a candidate's attendance frequency is measured.
     */
    private const CANDIDATE_WINDOW_DAYS = 90;

    /**
     * Weight (0-1) given to a day a candidate only attended a matching shift at a nearby
     * workplace. A day worked at this workplace always counts as a full 1.0.
     */
    private const NEARBY_DAY_WEIGHT = 0.7;

    /**
     * Attendance ratio (share of opportunity days worked) that maps to the maximum candidate
     * confidence. No individual works every day a shift runs, so dividing by the raw opportunity
     * count makes even the most regular worker look unreliable (in practice the most regular
     * reach ~0.6). Crediting full confidence once a worker attends this fraction calibrates the
     * score to a realistic cadence instead of an unreachable "every single day" baseline.
     */
    private const CANDIDATE_FULL_CREDIT_RATIO = 0.6;

    /**
     * Confidence ceiling (percent) for a candidate's show-up score. Deliberately below 100: even
     * the most regular worker is never a certainty to accept and attend any given shift.
     */
    private const CANDIDATE_MAX_CONFIDENCE = 90.0;

    /**
     * Hard cap on the number of ranked candidates returned, so a very large workplace
     * roster cannot bloat the response. The top scorers are kept.
     */
    private const CANDIDATE_LIST_LIMIT = 200;

    private const CHECKPOINTS = [
        'd_14' => ['label' => 'D-14', 'hours' => 336],
        'd_7' => ['label' => 'D-7', 'hours' => 168],
        'd_3' => ['label' => 'D-3', 'hours' => 72],
        'd_1' => ['label' => 'D-1', 'hours' => 24],
        'h_6' => ['label' => 'H-6', 'hours' => 6],
        'h_2' => ['label' => 'H-2', 'hours' => 2],
        'start' => ['label' => 'Start', 'hours' => 0],
    ];

    public function __construct(private readonly SimilarShiftService $similarShiftService) {}

    public function __invoke(DashboardWorkplaceResolver $resolver, array $data): ActionResult
    {
        $workplaceId = (int) $data['workplace_id'];
        $workplaceShiftId = (int) $data['workplace_shift_id'];
        $targetDate = Carbon::parse($data['date'], self::TIMEZONE)->startOfDay();
        $requestedCount = (int) $data['requested_count'];
        $radiusKm =
            isset($data['radius_km']) && $data['radius_km'] !== null
                ? (float) $data['radius_km']
                : self::DEFAULT_NEARBY_RADIUS_KM;

        if (!$this->canAccess($resolver->demand(), $workplaceId)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $workplaceShift = WorkplaceShift::query()
            ->where('id', $workplaceShiftId)
            ->where('workplace_id', $workplaceId)
            ->first();

        if (!$workplaceShift) {
            return new ActionResult(error: ErrorCode::UnprocessableEntity, status: 422);
        }

        $similarWorkplaceShiftIds = $this->similarShiftService->similarWorkplaceShiftIds(
            [$workplaceId],
            $workplaceShift
        );

        // The callable pool also reaches workers registered at nearby workplaces, so the
        // pool estimate looks at the target plus everything within the radius. Demand,
        // reliability and fill-speed stay scoped to the target workplace only.
        $workplace = Workplace::query()->select('id', 'latitude', 'longitude')->find($workplaceId);
        $nearbyWorkplaceIds = $workplace
            ? (new FindNearbyWorkplacesAction())($workplace, $radiusKm)->pluck('id')->all()
            : [];
        $poolWorkplaceIds = array_values(array_unique(array_merge([$workplaceId], $nearbyWorkplaceIds)));
        $poolSimilarWorkplaceShiftIds = $this->similarShiftService->similarWorkplaceShiftIds(
            $poolWorkplaceIds,
            $workplaceShift
        );

        $reliabilityIds = $resolver->reliability();
        $targetReliabilityIds = $resolver->intersectWithRequested($reliabilityIds, [$workplaceId]);
        $reliabilityAvailable = !$resolver->isEmpty($reliabilityIds);
        $targetReliabilityAvailable = !$resolver->isEmpty($targetReliabilityIds);

        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $historyEnd = $today->copy()->subDay();
        $oneYearStart = $historyEnd->copy()->subDays(364);
        $lastThreeMonthsStart = $historyEnd->copy()->subMonthsNoOverflow(3)->addDay();
        $sameMonthLastYear = $targetDate->copy()->subYearNoOverflow();

        $recentRows = $this->fetchShiftRows(
            $workplaceId,
            $similarWorkplaceShiftIds,
            $oneYearStart,
            $historyEnd,
            $targetReliabilityAvailable
        );
        $lastThreeMonthsRows = $this->fetchShiftRows(
            $workplaceId,
            $similarWorkplaceShiftIds,
            $lastThreeMonthsStart,
            $historyEnd,
            $targetReliabilityAvailable
        );
        $sameMonthLastYearRows = $this->fetchShiftRows(
            $workplaceId,
            $similarWorkplaceShiftIds,
            $sameMonthLastYear->copy()->startOfMonth(),
            $sameMonthLastYear->copy()->endOfMonth(),
            $targetReliabilityAvailable
        );

        $targetDayOfWeek = $targetDate->dayOfWeek;
        $demandContext = [
            'recent_1_year' => [
                'all_weekdays' => $this->summarizeDemandRows($recentRows, $targetReliabilityAvailable),
                'same_weekday' => $this->summarizeDemandRows(
                    $this->filterRowsByDayOfWeek($recentRows, $targetDayOfWeek),
                    $targetReliabilityAvailable
                ),
            ],
            'last_3_months' => [
                'all_weekdays' => $this->summarizeDemandRows($lastThreeMonthsRows, $targetReliabilityAvailable),
                'same_weekday' => $this->summarizeDemandRows(
                    $this->filterRowsByDayOfWeek($lastThreeMonthsRows, $targetDayOfWeek),
                    $targetReliabilityAvailable
                ),
            ],
            'same_month_last_year' => [
                'all_weekdays' => $this->summarizeDemandRows($sameMonthLastYearRows, $targetReliabilityAvailable),
                'same_weekday' => $this->summarizeDemandRows(
                    $this->filterRowsByDayOfWeek($sameMonthLastYearRows, $targetDayOfWeek),
                    $targetReliabilityAvailable
                ),
            ],
        ];

        $reliability = $this->buildReliability(
            $workplaceId,
            $similarWorkplaceShiftIds,
            $oneYearStart,
            $historyEnd,
            $reliabilityIds,
            $reliabilityAvailable
        );

        $fillSpeed = $this->buildFillSpeed($recentRows);
        $pool = $this->buildPool(
            $workplaceId,
            $similarWorkplaceShiftIds,
            $poolWorkplaceIds,
            $poolSimilarWorkplaceShiftIds,
            $radiusKm,
            $targetDate,
            $workplaceShift,
            $historyEnd,
            $targetReliabilityAvailable
        );

        $recommendation = $this->buildRecommendation(
            $requestedCount,
            $reliability,
            $pool,
            $fillSpeed,
            $demandContext,
            $reliabilityAvailable,
            count($recentRows)
        );

        $candidates = $this->buildCandidates(
            $workplaceId,
            $similarWorkplaceShiftIds,
            $poolWorkplaceIds,
            $poolSimilarWorkplaceShiftIds,
            $targetDate,
            $workplaceShift,
            $historyEnd,
            $today
        );

        [$targetStart, $targetEnd] = WorkHelper::getShiftTimes(
            $targetDate->toDateString(),
            $workplaceShift->start_time,
            $workplaceShift->end_time,
            null,
            null
        );

        return new ActionResult(
            data: [
                'input' => [
                    'workplace_id' => $workplaceId,
                    'workplace_shift_id' => $workplaceShiftId,
                    'date' => $targetDate->toDateString(),
                    'day_of_week' => $targetDate->dayOfWeek,
                    'requested_count' => $requestedCount,
                    'shift_start' => $targetStart->toDateTimeString(),
                    'shift_end' => $targetEnd->toDateTimeString(),
                    'lead_time_hours' => max(
                        0,
                        round(($targetStart->getTimestamp() - Carbon::now(self::TIMEZONE)->getTimestamp()) / 3600, 1)
                    ),
                ],
                'recommendation' => $recommendation,
                'demand_context' => $demandContext,
                'reliability' => $this->publicReliability($reliability),
                'fill_speed' => $fillSpeed,
                'pool' => $pool,
                'candidates' => $candidates,
                'warnings' => $this->buildWarnings($recommendation, $reliability, count($recentRows)),
            ]
        );
    }

    private function canAccess(?array $workplaceIds, int $workplaceId): bool
    {
        return $workplaceIds === null || in_array($workplaceId, $workplaceIds, true);
    }

    private function fetchShiftRows(
        int $workplaceId,
        array $workplaceShiftIds,
        Carbon $from,
        Carbon $to,
        bool $includeReliability
    ): array {
        if ($to->lt($from)) {
            return [];
        }

        $query = DB::table('shifts')
            ->join('workplace_shifts', 'workplace_shifts.id', '=', 'shifts.workplace_shift_id')
            ->leftJoin('shift_users', 'shift_users.shift_id', '=', 'shifts.id')
            ->where('shifts.workplace_id', $workplaceId)
            ->whereIn('shifts.workplace_shift_id', $workplaceShiftIds)
            ->whereBetween('shifts.date', [$from->toDateString(), $to->toDateString()]);

        if ($includeReliability) {
            $query
                ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
                ->leftJoin('shift_absent_notices', AbsenceService::standingAbsentNoticeJoin());
        }

        $select = '
            shifts.id,
            shifts.date,
            shifts.created_at,
            shifts.fixed_total,
            shifts.temp_total,
            workplace_shifts.start_time,
            workplace_shifts.end_time,
            COUNT(DISTINCT CASE WHEN shift_users.status = ? THEN shift_users.id END) as approved_count,
            COUNT(DISTINCT CASE WHEN shift_users.status = ? THEN shift_users.id END) as pending_count,
            COUNT(DISTINCT CASE WHEN shift_users.status = ? THEN shift_users.id END) as rejected_count,
            COUNT(DISTINCT CASE WHEN shift_users.status IN (?, ?) THEN shift_users.id END) as registered_count,
            COUNT(DISTINCT CASE WHEN shift_users.status = ? AND shift_users.confirmed_at IS NOT NULL THEN shift_users.id END) as confirmed_count
        ';
        $bindings = [
            ShiftUserStatus::Approved->value,
            ShiftUserStatus::Pending->value,
            ShiftUserStatus::Rejected->value,
            ShiftUserStatus::Approved->value,
            ShiftUserStatus::Pending->value,
            ShiftUserStatus::Approved->value,
        ];

        if ($includeReliability) {
            $select .= ',
                COUNT(DISTINCT CASE WHEN shift_users.status = ? THEN check_ins.id END) as check_in_count,
                COUNT(DISTINCT CASE WHEN shift_users.status = ? THEN shift_absent_notices.id END) as absent_notice_count
            ';
            $bindings[] = ShiftUserStatus::Approved->value;
            $bindings[] = ShiftUserStatus::Approved->value;
        }

        return $query
            ->selectRaw($select, $bindings)
            ->groupBy(
                'shifts.id',
                'shifts.date',
                'shifts.created_at',
                'shifts.fixed_total',
                'shifts.temp_total',
                'workplace_shifts.start_time',
                'workplace_shifts.end_time'
            )
            ->orderBy('shifts.date')
            ->get()
            ->map(function ($row) use ($includeReliability) {
                $requested = (int) $row->fixed_total + (int) $row->temp_total;

                return [
                    'id' => (int) $row->id,
                    'date' => (string) $row->date,
                    'day_of_week' => Carbon::parse($row->date, self::TIMEZONE)->dayOfWeek,
                    'created_at' => (string) $row->created_at,
                    'start_time' => mb_substr((string) $row->start_time, 0, 5),
                    'end_time' => mb_substr((string) $row->end_time, 0, 5),
                    'requested_count' => $requested,
                    'approved_count' => (int) $row->approved_count,
                    'pending_count' => (int) $row->pending_count,
                    'rejected_count' => (int) $row->rejected_count,
                    'registered_count' => (int) $row->registered_count,
                    'confirmed_count' => (int) $row->confirmed_count,
                    'check_in_count' => $includeReliability ? (int) $row->check_in_count : null,
                    'absent_notice_count' => $includeReliability ? (int) $row->absent_notice_count : null,
                ];
            })
            ->toArray();
    }

    private function filterRowsByDayOfWeek(array $rows, int $dayOfWeek): array
    {
        return array_values(array_filter($rows, fn(array $row) => $row['day_of_week'] === $dayOfWeek));
    }

    private function summarizeDemandRows(array $rows, bool $includeReliability): array
    {
        $shiftCount = count($rows);

        if ($shiftCount === 0) {
            return [
                'shift_count' => 0,
                'avg_requested' => null,
                'avg_approved' => null,
                'avg_check_in' => null,
                'avg_fill_rate' => null,
                'avg_show_up_rate' => null,
            ];
        }

        $fillRates = [];
        $showUpRates = [];
        foreach ($rows as $row) {
            if ($row['requested_count'] > 0) {
                $fillRates[] = min(1, $row['approved_count'] / $row['requested_count']) * 100;
            }
            if ($includeReliability && $row['approved_count'] > 0 && $row['check_in_count'] !== null) {
                $showUpRates[] = min(1, $row['check_in_count'] / $row['approved_count']) * 100;
            }
        }

        return [
            'shift_count' => $shiftCount,
            'avg_requested' => $this->average(array_column($rows, 'requested_count')),
            'avg_approved' => $this->average(array_column($rows, 'approved_count')),
            'avg_check_in' => $includeReliability ? $this->average(array_column($rows, 'check_in_count')) : null,
            'avg_fill_rate' => $this->average($fillRates),
            'avg_show_up_rate' => $includeReliability ? $this->average($showUpRates) : null,
        ];
    }

    private function buildReliability(
        int $workplaceId,
        array $workplaceShiftIds,
        Carbon $from,
        Carbon $to,
        ?array $reliabilityIds,
        bool $reliabilityAvailable
    ): array {
        if (!$reliabilityAvailable) {
            return [
                'source' => null,
                'sample_size' => 0,
                'approved_count' => 0,
                'check_in_count' => 0,
                'show_up_rate' => null,
                'no_show_rate' => null,
                'absent_notice_rate' => null,
                'confidence' => 'low',
                'show_up_ratio' => null,
            ];
        }

        $candidates = [
            $this->reliabilityAggregate('exact_shift', $from, $to, $reliabilityIds, $workplaceId, $workplaceShiftIds),
            $this->reliabilityAggregate('workplace', $from, $to, $reliabilityIds, $workplaceId, null),
            $this->reliabilityAggregate('accessible_workplaces', $from, $to, $reliabilityIds, null, null),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate['approved_count'] >= self::RELIABILITY_MIN_ASSIGNMENTS) {
                return $candidate;
            }
        }

        return $candidates[0]['approved_count'] > 0 ? $candidates[0] : $candidates[2];
    }

    private function reliabilityAggregate(
        string $source,
        Carbon $from,
        Carbon $to,
        ?array $reliabilityIds,
        ?int $workplaceId,
        ?array $workplaceShiftIds
    ): array {
        $query = DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->leftJoin('shift_absent_notices', AbsenceService::standingAbsentNoticeJoin())
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$from->toDateString(), $to->toDateString()]);

        if ($reliabilityIds !== null) {
            $query->whereIn('shifts.workplace_id', $reliabilityIds);
        }
        if ($workplaceId !== null) {
            $query->where('shifts.workplace_id', $workplaceId);
        }
        if ($workplaceShiftIds !== null) {
            $query->whereIn('shifts.workplace_shift_id', $workplaceShiftIds);
        }

        $row = $query
            ->selectRaw(
                '
                COUNT(DISTINCT shifts.id) as shift_count,
                COUNT(DISTINCT shift_users.id) as approved_count,
                COUNT(DISTINCT check_ins.id) as check_in_count,
                COUNT(DISTINCT shift_absent_notices.id) as absent_notice_count
            '
            )
            ->first();

        $approved = (int) ($row->approved_count ?? 0);
        $checkIns = (int) ($row->check_in_count ?? 0);
        $absentNotices = (int) ($row->absent_notice_count ?? 0);
        $showUpRatio = $approved > 0 ? min(1, $checkIns / $approved) : null;

        return [
            'source' => $source,
            'sample_size' => (int) ($row->shift_count ?? 0),
            'approved_count' => $approved,
            'check_in_count' => $checkIns,
            'show_up_rate' => $showUpRatio !== null ? round($showUpRatio * 100, 1) : null,
            'no_show_rate' => $showUpRatio !== null ? round((1 - $showUpRatio) * 100, 1) : null,
            'absent_notice_rate' => $approved > 0 ? round(($absentNotices / $approved) * 100, 1) : null,
            'confidence' => $this->confidence((int) ($row->shift_count ?? 0), $approved),
            'show_up_ratio' => $showUpRatio,
        ];
    }

    private function publicReliability(array $reliability): array
    {
        unset($reliability['show_up_ratio']);

        return $reliability;
    }

    private function buildFillSpeed(array $rows): array
    {
        if (empty($rows)) {
            return [
                'checkpoints' => $this->emptyCheckpointRows(),
                'average_hours_before_start_to_target' => null,
                'reach_target_rate' => null,
                'shortage_h6_rate' => null,
                'shortage_h2_rate' => null,
                'emergency_add_rate' => null,
                'avg_emergency_add_count' => null,
            ];
        }

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[$row['id']] = $row;
        }

        $approvalRows = DB::table('shift_users')
            ->whereIn('shift_id', array_keys($rowsById))
            ->whereIn('status', [ShiftUserStatus::Approved->value, ShiftUserStatus::Pending->value])
            ->select('shift_id', 'status', 'approved_at', 'created_at', 'created_by')
            ->get();

        $approvalsByShift = [];
        $registrationsByShift = [];
        foreach ($approvalRows as $approval) {
            if (!$approval->created_at) {
                continue;
            }

            $registrationsByShift[(int) $approval->shift_id][] = Carbon::parse($approval->created_at, self::TIMEZONE);

            if ($approval->status === ShiftUserStatus::Approved->value) {
                $approvedAt = $approval->approved_at ?: $approval->created_at;
                $approvalsByShift[(int) $approval->shift_id][] = [
                    'approved_at' => Carbon::parse($approvedAt, self::TIMEZONE),
                    'created_by' => $approval->created_by,
                ];
            }
        }

        $checkpointTotals = [];
        foreach (array_keys(self::CHECKPOINTS) as $key) {
            $checkpointTotals[$key] = [
                'approved_count' => 0,
                'registered_rate' => 0,
                'fill_rate' => 0,
                'shortage_count' => 0,
            ];
        }

        $targetHoursBeforeStart = [];
        $reachedTargetCount = 0;
        $emergencyShiftCount = 0;
        $emergencyAddTotal = 0;
        $sampleCount = count($rows);

        foreach ($rows as $row) {
            [$shiftStart] = WorkHelper::getShiftTimes($row['date'], $row['start_time'], $row['end_time'], null, null);
            $requested = max(1, (int) $row['requested_count']);
            $approvals = $approvalsByShift[$row['id']] ?? [];
            usort(
                $approvals,
                fn(array $a, array $b) => $a['approved_at']->getTimestamp() <=> $b['approved_at']->getTimestamp()
            );

            $approvedTimes = array_map(fn(array $approval) => $approval['approved_at'], $approvals);
            $registeredTimes = $registrationsByShift[$row['id']] ?? [];
            usort($registeredTimes, fn(Carbon $a, Carbon $b) => $a->getTimestamp() <=> $b->getTimestamp());
            foreach (self::CHECKPOINTS as $key => $checkpoint) {
                $threshold = $shiftStart->copy()->subHours($checkpoint['hours']);
                $count = $this->countEventsBy($approvedTimes, $threshold);
                $registeredCount = $this->countEventsBy($registeredTimes, $threshold);
                $checkpointTotals[$key]['approved_count'] += $count;
                $checkpointTotals[$key]['registered_rate'] += min(1, $registeredCount / $requested);
                $checkpointTotals[$key]['fill_rate'] += min(1, $count / $requested);
                if ($count < $requested) {
                    $checkpointTotals[$key]['shortage_count']++;
                }
            }

            if (count($approvedTimes) >= $requested) {
                $targetTime = $approvedTimes[$requested - 1];
                $targetHoursBeforeStart[] = round(
                    max(0, ($shiftStart->getTimestamp() - $targetTime->getTimestamp()) / 3600),
                    1
                );
                $reachedTargetCount++;
            }

            $emergencyCount = 0;
            $emergencyWindowStart = $shiftStart->copy()->subHours(6);
            foreach ($approvals as $approval) {
                if (
                    $approval['created_by'] !== null &&
                    $approval['approved_at']->gte($emergencyWindowStart) &&
                    $approval['approved_at']->lte($shiftStart)
                ) {
                    $emergencyCount++;
                }
            }
            if ($emergencyCount > 0) {
                $emergencyShiftCount++;
            }
            $emergencyAddTotal += $emergencyCount;
        }

        $checkpointRows = [];
        foreach (self::CHECKPOINTS as $key => $checkpoint) {
            $checkpointRows[] = [
                'key' => $key,
                'label' => $checkpoint['label'],
                'sample_size' => $sampleCount,
                'avg_approved_count' => round($checkpointTotals[$key]['approved_count'] / $sampleCount, 1),
                'average_registered_rate' => round(
                    ($checkpointTotals[$key]['registered_rate'] / $sampleCount) * 100,
                    1
                ),
                'average_fill_rate' => round(($checkpointTotals[$key]['fill_rate'] / $sampleCount) * 100, 1),
                'shortage_rate' => round(($checkpointTotals[$key]['shortage_count'] / $sampleCount) * 100, 1),
            ];
        }

        return [
            'checkpoints' => $checkpointRows,
            'average_hours_before_start_to_target' => $this->average($targetHoursBeforeStart),
            'reach_target_rate' => round(($reachedTargetCount / $sampleCount) * 100, 1),
            'shortage_h6_rate' => $this->checkpointRate($checkpointRows, 'h_6', 'shortage_rate'),
            'shortage_h2_rate' => $this->checkpointRate($checkpointRows, 'h_2', 'shortage_rate'),
            'emergency_add_rate' => round(($emergencyShiftCount / $sampleCount) * 100, 1),
            'avg_emergency_add_count' => round($emergencyAddTotal / $sampleCount, 1),
        ];
    }

    private function emptyCheckpointRows(): array
    {
        $rows = [];
        foreach (self::CHECKPOINTS as $key => $checkpoint) {
            $rows[] = [
                'key' => $key,
                'label' => $checkpoint['label'],
                'sample_size' => 0,
                'avg_approved_count' => null,
                'average_registered_rate' => null,
                'average_fill_rate' => null,
                'shortage_rate' => null,
            ];
        }

        return $rows;
    }

    private function countEventsBy(array $eventTimes, Carbon $threshold): int
    {
        $count = 0;
        foreach ($eventTimes as $eventTime) {
            if ($eventTime->lte($threshold)) {
                $count++;
            }
        }

        return $count;
    }

    private function checkpointRate(array $checkpointRows, string $key, string $field): ?float
    {
        foreach ($checkpointRows as $row) {
            if ($row['key'] === $key) {
                return $row[$field];
            }
        }

        return null;
    }

    private function buildPool(
        int $workplaceId,
        array $workplaceShiftIds,
        array $poolWorkplaceIds,
        array $poolWorkplaceShiftIds,
        float $radiusKm,
        Carbon $targetDate,
        WorkplaceShift $workplaceShift,
        Carbon $historyEnd,
        bool $reliabilityAvailable
    ): array {
        $eligibleMembers = DB::table('workplace_users')
            ->join('users', 'users.id', '=', 'workplace_users.user_id')
            ->where('workplace_users.workplace_id', $workplaceId)
            ->where('users.is_disabled', false)
            ->whereNotNull('users.staff_no')
            ->where('users.paper_status', '!=', UserPaperStatus::Rejected->value)
            ->distinct('users.id')
            ->count('users.id');

        // Members of THIS workplace already committed (approved) on the target date. They are
        // dropped from the pool rather than subtracted afterwards: the active-pool basis is a
        // *weighted* count (irregular workers contribute a fraction), so subtracting a raw body
        // count would mix scales and could zero out an otherwise healthy pool. Excluding them up
        // front keeps everything on one scale and matches buildCandidates()' assigned exclusion.
        $targetAdjustedDate = $targetDate->copy()->addDays((int) $workplaceShift->date_adjustment)->toDateString();
        $assignedUserIds = DB::table('shift_users')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->join('workplace_users', function ($join) use ($workplaceId) {
                $join
                    ->on('workplace_users.user_id', '=', 'shift_users.user_id')
                    ->where('workplace_users.workplace_id', '=', $workplaceId);
            })
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->where('shifts.adjusted_date', $targetAdjustedDate)
            ->distinct()
            ->pluck('shift_users.user_id')
            ->map(fn($id) => (int) $id)
            ->all();
        $alreadyAssignedSameDay = count($assignedUserIds);

        $active90 = $reliabilityAvailable
            ? $this->activePoolCount(
                $workplaceId,
                $workplaceShiftIds,
                $poolWorkplaceIds,
                $poolWorkplaceShiftIds,
                $historyEnd->copy()->subDays(89),
                $historyEnd,
                $assignedUserIds
            )
            : null;
        $active365 = $reliabilityAvailable
            ? $this->activePoolCount(
                $workplaceId,
                $workplaceShiftIds,
                $poolWorkplaceIds,
                $poolWorkplaceShiftIds,
                $historyEnd->copy()->subDays(364),
                $historyEnd,
                $assignedUserIds
            )
            : null;

        // Fixed-schedule members already taken that day are likewise not callable, so the basis
        // counts only the free ones.
        $fixedScheduleUserIds = DB::table('fixed_schedules')
            ->join('fixed_schedule_details', 'fixed_schedule_details.fixed_schedule_id', '=', 'fixed_schedules.id')
            ->where('fixed_schedules.workplace_id', $workplaceId)
            ->whereIn('fixed_schedule_details.workplace_shift_id', $workplaceShiftIds)
            ->where('fixed_schedule_details.day', $targetDate->dayOfWeek)
            ->distinct()
            ->pluck('fixed_schedules.user_id')
            ->map(fn($id) => (int) $id)
            ->all();
        $fixedScheduleMatches = count($fixedScheduleUserIds);
        $fixedScheduleFree = count(array_diff($fixedScheduleUserIds, $assignedUserIds));

        // All three terms now exclude already-committed members, so the estimate needs no further
        // subtraction.
        $regularPoolBasis = max(
            (float) ($active90 ?? 0),
            round((float) ($active365 ?? 0) * 0.6, 1),
            (float) $fixedScheduleFree
        );

        return [
            'radius_km' => $radiusKm,
            'nearby_workplace_count' => max(0, count($poolWorkplaceIds) - 1),
            'eligible_workplace_members' => (int) $eligibleMembers,
            'active_90_day_pool' => $active90,
            'active_365_day_pool' => $active365,
            'fixed_schedule_matches' => $fixedScheduleMatches,
            'already_assigned_same_adjusted_date' => $alreadyAssignedSameDay,
            'available_pool_estimate' => round(max(0, $regularPoolBasis), 1),
            // Extended pool stays a raw headcount: eligible members minus those already taken.
            'available_extended_pool' => max(
                0,
                round(max((float) $eligibleMembers - $alreadyAssignedSameDay, (float) ($active365 ?? 0)), 1)
            ),
        ];
    }

    private function activePoolCount(
        int $workplaceId,
        array $workplaceShiftIds,
        array $poolWorkplaceIds,
        array $poolWorkplaceShiftIds,
        Carbon $from,
        Carbon $to,
        array $excludeUserIds = []
    ): float {
        if ($to->lt($from)) {
            return 0;
        }

        // Denominator stays the target workplace's own opportunities, so a worker who
        // reliably staffs this site keeps full credit and nearby activity can only ever
        // raise a worker toward it (never dilute a target regular).
        $shiftCount = DB::table('shifts')
            ->where('workplace_id', $workplaceId)
            ->whereIn('workplace_shift_id', $workplaceShiftIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->count('id');

        if ($shiftCount === 0) {
            return 0;
        }

        // Numerator counts shifts worked across the target plus nearby workplaces, but only
        // for users who are members of the target workplace, so proximity boosts the weight
        // of callable members without admitting non-members. Members already committed on the
        // target date are excluded so the basis reflects only workers still free to call.
        $workedCounts = DB::table('check_ins')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->join('workplace_users', function ($join) use ($workplaceId) {
                $join
                    ->on('workplace_users.user_id', '=', 'check_ins.user_id')
                    ->where('workplace_users.workplace_id', '=', $workplaceId);
            })
            ->whereIn('shifts.workplace_id', $poolWorkplaceIds)
            ->whereIn('shifts.workplace_shift_id', $poolWorkplaceShiftIds)
            ->whereBetween('shifts.date', [$from->toDateString(), $to->toDateString()])
            ->when(!empty($excludeUserIds), fn($query) => $query->whereNotIn('check_ins.user_id', $excludeUserIds))
            ->selectRaw('check_ins.user_id, COUNT(DISTINCT shifts.id) as worked_shift_count')
            ->groupBy('check_ins.user_id')
            ->get();

        $weightedTotal = 0;
        foreach ($workedCounts as $workedCount) {
            // Full credit once a worker reaches POOL_FULL_CREDIT_SHIFT_RATIO of the
            // shifts, proportional partial credit below it, capped at 1 per person.
            $attendanceRatio = (int) $workedCount->worked_shift_count / $shiftCount;
            $weightedTotal += min(1, $attendanceRatio / self::POOL_FULL_CREDIT_SHIFT_RATIO);
        }

        return round($weightedTotal, 1);
    }

    /**
     * Build the ranked list of callable employees for the target shift, ordered by a
     * per-worker "show-up confidence" score (highest first).
     *
     * Candidates are the eligible members of the target workplace. Anyone already approved
     * on the target adjusted date is dropped, since they are no longer callable. The score
     * is an attendance frequency over the last CANDIDATE_WINDOW_DAYS days: of the distinct
     * days this workplace actually ran the matching shift (exact + same-time-range), what
     * share did the worker attend. A day worked at this workplace counts 1.0; a day they
     * only attended a matching shift at a nearby workplace counts NEARBY_DAY_WEIGHT. Because
     * a worker can join at most one shift per day, the per-day weight is capped at 1.0. The
     * raw share is then calibrated against a realistic regular cadence (CANDIDATE_FULL_CREDIT_RATIO)
     * and capped at CANDIDATE_MAX_CONFIDENCE, since no one works every opportunity day and a
     * worker accepting any given call is never certain.
     */
    private function buildCandidates(
        int $workplaceId,
        array $workplaceShiftIds,
        array $poolWorkplaceIds,
        array $poolWorkplaceShiftIds,
        Carbon $targetDate,
        WorkplaceShift $workplaceShift,
        Carbon $historyEnd,
        Carbon $today
    ): array {
        $members = DB::table('workplace_users')
            ->join('users', 'users.id', '=', 'workplace_users.user_id')
            ->where('workplace_users.workplace_id', $workplaceId)
            ->where('users.is_disabled', false)
            ->whereNotNull('users.staff_no')
            ->where('users.paper_status', '!=', UserPaperStatus::Rejected->value)
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.short_name', 'users.staff_no')
            ->distinct()
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        $memberIds = $members->pluck('id')->map(fn($id) => (int) $id)->all();
        $windowStart = $historyEnd->copy()->subDays(self::CANDIDATE_WINDOW_DAYS - 1);
        $nearbyWorkplaceIds = array_values(array_diff($poolWorkplaceIds, [$workplaceId]));

        // Opportunity days: distinct dates this workplace ran a matching shift. Multiple
        // shifts at the same time on one date collapse to a single day, since a worker can
        // only take one of them.
        $opportunityDates = DB::table('shifts')
            ->where('workplace_id', $workplaceId)
            ->whereIn('workplace_shift_id', $workplaceShiftIds)
            ->whereBetween('date', [$windowStart->toDateString(), $historyEnd->toDateString()])
            ->distinct()
            ->pluck('date')
            ->map(fn($date) => Carbon::parse($date, self::TIMEZONE)->toDateString())
            ->unique();
        $opportunityDays = $opportunityDates->count();
        $opportunitySet = array_fill_keys($opportunityDates->all(), true);

        // Distinct dates each member checked in to a matching shift, at this workplace and
        // (separately) at nearby workplaces.
        $targetWorked = $this->candidateWorkedDates(
            $memberIds,
            [$workplaceId],
            $workplaceShiftIds,
            $windowStart,
            $historyEnd
        );
        $nearbyWorked = $this->candidateWorkedDates(
            $memberIds,
            $nearbyWorkplaceIds,
            $poolWorkplaceShiftIds,
            $windowStart,
            $historyEnd
        );

        $lastWorked = DB::table('check_ins')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->whereIn('check_ins.user_id', $memberIds)
            ->whereIn('shifts.workplace_id', $poolWorkplaceIds)
            ->whereIn('shifts.workplace_shift_id', $poolWorkplaceShiftIds)
            ->whereBetween('shifts.date', [
                $historyEnd->copy()->subDays(364)->toDateString(),
                $historyEnd->toDateString(),
            ])
            ->groupBy('check_ins.user_id')
            ->selectRaw('check_ins.user_id, MAX(shifts.date) as last_worked_date')
            ->get()
            ->keyBy('user_id');

        $fixedScheduleUserIds = DB::table('fixed_schedules')
            ->join('fixed_schedule_details', 'fixed_schedule_details.fixed_schedule_id', '=', 'fixed_schedules.id')
            ->where('fixed_schedules.workplace_id', $workplaceId)
            ->whereIn('fixed_schedule_details.workplace_shift_id', $workplaceShiftIds)
            ->where('fixed_schedule_details.day', $targetDate->dayOfWeek)
            ->distinct()
            ->pluck('fixed_schedules.user_id')
            ->map(fn($id) => (int) $id)
            ->flip();

        $targetAdjustedDate = $targetDate->copy()->addDays((int) $workplaceShift->date_adjustment)->toDateString();
        $assignedUserIds = DB::table('shift_users')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->where('shifts.adjusted_date', $targetAdjustedDate)
            ->whereIn('shift_users.user_id', $memberIds)
            ->distinct()
            ->pluck('shift_users.user_id')
            ->map(fn($id) => (int) $id)
            ->flip();

        $candidates = [];
        foreach ($members as $member) {
            $userId = (int) $member->id;
            if ($assignedUserIds->has($userId)) {
                continue;
            }

            $targetDatesMap = $targetWorked[$userId] ?? [];
            $nearbyDatesMap = $nearbyWorked[$userId] ?? [];

            // Days attended at this workplace (all such dates are opportunity days by
            // definition) and days only covered at a nearby workplace on an opportunity day.
            $targetDayCount = 0;
            foreach ($targetDatesMap as $date => $unused) {
                if (isset($opportunitySet[$date])) {
                    $targetDayCount++;
                }
            }
            $nearbyDayCount = 0;
            foreach ($nearbyDatesMap as $date => $unused) {
                if (isset($opportunitySet[$date]) && !isset($targetDatesMap[$date])) {
                    $nearbyDayCount++;
                }
            }

            // A worker who attended none of the opportunity days has no track record to call on,
            // so a score-0 row is noise rather than a realistic candidate. Drop them — unless they
            // are on the fixed schedule for this shift, where attendance is contractually expected.
            // This keeps the list scoped to genuinely callable workers (so its size stays roughly
            // in line with the active pool) instead of dumping the entire eligible roster.
            $isFixedSchedule = $fixedScheduleUserIds->has($userId);
            if ($targetDayCount === 0 && $nearbyDayCount === 0 && !$isFixedSchedule) {
                continue;
            }

            // Calibrated confidence: attendance is scaled so reaching CANDIDATE_FULL_CREDIT_RATIO
            // of opportunity days earns the CANDIDATE_MAX_CONFIDENCE ceiling, then capped there.
            // This keeps a realistic regular near the top instead of penalising everyone for not
            // working every single day, while never promising a full 100%.
            $weightedDays = $targetDayCount + self::NEARBY_DAY_WEIGHT * $nearbyDayCount;
            $score =
                $opportunityDays > 0
                    ? round(
                        min(
                            self::CANDIDATE_MAX_CONFIDENCE,
                            ($weightedDays / $opportunityDays / self::CANDIDATE_FULL_CREDIT_RATIO) *
                                self::CANDIDATE_MAX_CONFIDENCE
                        ),
                        1
                    )
                    : null;
            $lastWorkedDate = $lastWorked->get($userId)->last_worked_date ?? null;

            $candidates[] = [
                'user_id' => $userId,
                'name' => $member->short_name ?: mb_trim($member->last_name . ' ' . $member->first_name),
                'staff_no' => $member->staff_no !== null ? (string) $member->staff_no : null,
                'score' => $score,
                'opportunity_days' => $opportunityDays,
                'worked_days' => $targetDayCount + $nearbyDayCount,
                'target_days' => $targetDayCount,
                'nearby_days' => $nearbyDayCount,
                'last_worked_date' => $lastWorkedDate
                    ? Carbon::parse($lastWorkedDate, self::TIMEZONE)->toDateString()
                    : null,
                'fixed_schedule' => $isFixedSchedule,
                'confidence' => $this->candidateConfidence($opportunityDays),
            ];
        }

        usort(
            $candidates,
            fn(array $a, array $b) => [$b['score'] ?? -1.0, $b['worked_days'], $a['name']] <=> [
                $a['score'] ?? -1.0,
                $a['worked_days'],
                $b['name'],
            ]
        );

        return array_slice($candidates, 0, self::CANDIDATE_LIST_LIMIT);
    }

    /**
     * Distinct dates each member checked in to a matching shift in the window, grouped by
     * user id and keyed by date string for fast lookup.
     *
     * @return array<int, array<string, true>>
     */
    private function candidateWorkedDates(
        array $memberIds,
        array $workplaceIds,
        array $workplaceShiftIds,
        Carbon $from,
        Carbon $to
    ): array {
        if (empty($workplaceIds) || empty($workplaceShiftIds) || empty($memberIds)) {
            return [];
        }

        $rows = DB::table('check_ins')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->whereIn('check_ins.user_id', $memberIds)
            ->whereIn('shifts.workplace_id', $workplaceIds)
            ->whereIn('shifts.workplace_shift_id', $workplaceShiftIds)
            ->whereBetween('shifts.date', [$from->toDateString(), $to->toDateString()])
            ->select('check_ins.user_id', 'shifts.date')
            ->distinct()
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->date, self::TIMEZONE)->toDateString();
            $map[(int) $row->user_id][$date] = true;
        }

        return $map;
    }

    /**
     * How much to trust a candidate's score, based on sample size. The score is a share of
     * opportunity days attended, so when the workplace only ran the shift a handful of times
     * in the window the ratio is statistically noisy (one extra absence swings it heavily).
     * This reports that as a per-candidate confidence — high (>= 12 opportunity days), medium
     * (>= 4), or low — so the UI can flag scores that rest on a thin history.
     */
    private function candidateConfidence(int $opportunityDays): string
    {
        if ($opportunityDays >= 12) {
            return 'high';
        }

        if ($opportunityDays >= 4) {
            return 'medium';
        }

        return 'low';
    }

    private function buildRecommendation(
        int $requestedCount,
        array $reliability,
        array $pool,
        array $fillSpeed,
        array $demandContext,
        bool $reliabilityAvailable,
        int $similarShiftCount
    ): array {
        $reasonCodes = [];

        if (!$reliabilityAvailable || $reliability['show_up_ratio'] === null || $reliability['show_up_ratio'] <= 0) {
            return [
                'decision' => 'insufficient_data',
                'risk_level' => 'high',
                'confidence' => 'low',
                'safe_commitment' => null,
                'expected_attendance' => null,
                'expected_shortage' => null,
                'suggested_approval_target' => null,
                'reason_codes' => ['reliability_unavailable'],
            ];
        }

        $confidence = $this->combinedConfidence(
            $reliability['confidence'],
            $similarShiftCount,
            $reliability['approved_count']
        );
        $showUpRatio = max(0.01, (float) $reliability['show_up_ratio']);
        $suggestedApprovalTarget = (int) ceil($requestedCount / $showUpRatio);
        $availablePool = (float) $pool['available_pool_estimate'];
        $fillableCount = max(0, min($suggestedApprovalTarget, $availablePool));
        $expectedAttendance = min($requestedCount, (int) round($fillableCount * $showUpRatio));
        $safeCommitment = min(
            $requestedCount,
            (int) floor($fillableCount * $showUpRatio * $this->confidenceFactor($confidence))
        );
        $expectedShortage = max(0, $requestedCount - $expectedAttendance);

        if ($confidence === 'low') {
            $reasonCodes[] = 'low_sample';
        }
        if ($reliability['source'] !== 'exact_shift') {
            $reasonCodes[] = 'reliability_fallback';
        }
        if ($availablePool < $suggestedApprovalTarget) {
            $reasonCodes[] = 'active_pool_below_target';
        }
        if (($reliability['no_show_rate'] ?? 0) >= 15) {
            $reasonCodes[] = 'high_no_show_rate';
        }
        // Use the 2-hours-before checkpoint: a shortage that close to start is a far
        // stronger signal than at 6 hours (when shifts still routinely fill in time).
        if (($fillSpeed['shortage_h2_rate'] ?? 0) >= 40) {
            $reasonCodes[] = 'frequent_h2_shortage';
        }
        $recentAverage =
            $demandContext['last_3_months']['same_weekday']['avg_requested'] ??
            $demandContext['last_3_months']['all_weekdays']['avg_requested'];
        if (
            $recentAverage !== null &&
            $recentAverage > 0 &&
            $requestedCount > $recentAverage * self::REQUEST_ABOVE_RECENT_AVERAGE_THRESHOLD
        ) {
            $reasonCodes[] = 'request_above_recent_average';
        }

        // Risk is driven by expected attendance after approving the over-booked target
        // (suggested_approval_target already absorbs the no-show loss), so a sufficient
        // pool can reach the full request even though no-shows are guaranteed.
        // safe_commitment remains a separate, more conservative floor for the UI.
        $coverageFloor = (int) ceil($requestedCount * self::SAFE_COVERAGE_THRESHOLD);
        if ($expectedAttendance >= $requestedCount) {
            $decision = 'accept';
            $riskLevel = $confidence === 'low' ? 'medium' : 'low';
        } elseif ($expectedAttendance >= $coverageFloor) {
            $decision = 'accept_with_backup';
            $riskLevel = 'medium';
        } else {
            $decision = 'negotiate';
            $riskLevel = 'high';
        }
        // Full coverage with a trustworthy sample can still carry softer warning signals.
        if (
            $riskLevel === 'low' &&
            (in_array('frequent_h2_shortage', $reasonCodes, true) ||
                in_array('reliability_fallback', $reasonCodes, true) ||
                in_array('request_above_recent_average', $reasonCodes, true))
        ) {
            $riskLevel = 'medium';
        }

        return [
            'decision' => $decision,
            'risk_level' => $riskLevel,
            'confidence' => $confidence,
            'safe_commitment' => $safeCommitment,
            'expected_attendance' => $expectedAttendance,
            'expected_shortage' => $expectedShortage,
            'suggested_approval_target' => $suggestedApprovalTarget,
            'reason_codes' => array_values(array_unique($reasonCodes)),
        ];
    }

    private function buildWarnings(array $recommendation, array $reliability, int $similarShiftCount): array
    {
        $warnings = [];
        if ($similarShiftCount < 5) {
            $warnings[] = 'low_similar_shift_sample';
        }
        if ($reliability['source'] !== null && $reliability['source'] !== 'exact_shift') {
            $warnings[] = 'reliability_uses_fallback_scope';
        }
        if ($recommendation['decision'] === 'insufficient_data') {
            $warnings[] = 'reliability_data_unavailable';
        }

        return $warnings;
    }

    private function combinedConfidence(
        string $reliabilityConfidence,
        int $similarShiftCount,
        int $assignmentCount
    ): string {
        if ($reliabilityConfidence === 'high' && $similarShiftCount >= 10 && $assignmentCount >= 100) {
            return 'high';
        }

        if ($reliabilityConfidence !== 'low' && $similarShiftCount >= 3 && $assignmentCount >= 30) {
            return 'medium';
        }

        return 'low';
    }

    private function confidence(int $shiftCount, int $assignmentCount): string
    {
        if ($shiftCount >= 10 && $assignmentCount >= 100) {
            return 'high';
        }

        if ($shiftCount >= 3 && $assignmentCount >= 30) {
            return 'medium';
        }

        return 'low';
    }

    private function confidenceFactor(string $confidence): float
    {
        return match ($confidence) {
            'high' => 0.95,
            'medium' => 0.9,
            default => 0.8,
        };
    }

    private function average(array $values): ?float
    {
        $values = array_values(array_filter($values, fn($value) => $value !== null));
        if (empty($values)) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }
}
