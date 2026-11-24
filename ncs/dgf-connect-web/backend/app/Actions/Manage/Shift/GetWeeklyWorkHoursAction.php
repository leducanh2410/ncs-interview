<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkLoadStatus;
use App\Helpers\ResponseHelper;
use App\Helpers\WorkHelper;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\UserConfig;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GetWeeklyWorkHoursAction
{
    public const DAYS_PER_WINDOW = 7;

    private const DEFAULT_PAGE_SIZE = 20;

    public function __invoke(User $actor, array $filters): ActionResult
    {
        [$from, $dayCount] = $this->resolvePeriod($filters);
        $page = Paginator::resolveCurrentPage();
        $pageSize = (int) ($filters['page_size'] ?? self::DEFAULT_PAGE_SIZE);

        if (!empty($filters['load_status'])) {
            $rows = $this->collectAll($actor, $filters)['rows'];
            $paginator = new LengthAwarePaginator(
                $rows->forPage($page, $pageSize)->values()->all(),
                $rows->count(),
                $pageSize,
                $page
            );
        } else {
            $users = $this->findUsers($actor, $filters, $from, $dayCount);
            $paginator = new LengthAwarePaginator(
                $this->buildRows($users->forPage($page, $pageSize)->values(), $from, $dayCount)->all(),
                $users->count(),
                $pageSize,
                $page
            );
        }

        return new ActionResult(
            data: [
                'weeks' => $this->buildWindows($from, $dayCount),
                ...ResponseHelper::transformPagination($paginator),
            ]
        );
    }

    /**
     * @return array{weeks: list<array{start: string, end: string}>, rows: Collection<int, array<string, mixed>>}
     */
    public function collectAll(User $actor, array $filters): array
    {
        [$from, $dayCount] = $this->resolvePeriod($filters);
        $users = $this->findUsers($actor, $filters, $from, $dayCount);
        $loadStatus = $filters['load_status'] ?? null;

        $rows = $this->buildRows($users, $from, $dayCount)
            ->when($loadStatus, fn(Collection $rows) => $rows->filter(fn(array $row) => $row['status'] === $loadStatus))
            ->values();

        return [
            'weeks' => $this->buildWindows($from, $dayCount),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{0: Carbon, 1: int}
     */
    private function resolvePeriod(array $filters): array
    {
        $from = Carbon::parse($filters['dates'][0])->startOfDay();
        $selectedDayCount = (int) $from->diffInDays(Carbon::parse($filters['dates'][1])->startOfDay()) + 1;

        return [$from, max($selectedDayCount, self::DAYS_PER_WINDOW)];
    }

    /**
     * @return list<int>
     */
    private function windowOffsets(int $dayCount): array
    {
        return range(0, $dayCount - self::DAYS_PER_WINDOW);
    }

    /**
     * @return Collection<int, User>
     */
    private function findUsers(User $actor, array $filters, Carbon $from, int $dayCount): Collection
    {
        $workplaceIds = $actor->getWorkplaceIdsByPermission(PermissionName::ShiftList->value);
        if ($workplaceIds !== null && empty($workplaceIds)) {
            return collect();
        }

        $workplaceId = isset($filters['workplace_id']) ? (int) $filters['workplace_id'] : null;
        if ($workplaceId !== null && $workplaceIds !== null && !in_array($workplaceId, $workplaceIds, true)) {
            return collect();
        }

        $to = $from->clone()->addDays($dayCount - 1);
        $workingUserIds = ShiftUser::query()
            ->select('shift_users.user_id')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->where('shift_users.is_absent', false)
            ->whereBetween('shifts.date', [$from->toDateString(), $to->toDateString()])
            ->when($workplaceIds !== null, fn(Builder $query) => $query->whereIn('shifts.workplace_id', $workplaceIds))
            ->when($workplaceId !== null, fn(Builder $query) => $query->where('shifts.workplace_id', $workplaceId));

        $users = User::query()
            ->with('info')
            ->whereIn('id', $workingUserIds)
            ->when(
                !empty($filters['staff_no']),
                fn(Builder $query) => $query->where('staff_no', 'like', '%' . $filters['staff_no'] . '%')
            )
            ->get();

        $residenceStatuses = $filters['residence_statuses'] ?? [];
        if (!empty($residenceStatuses)) {
            $users = $users->filter(
                fn(User $user) => in_array(ResidenceSlotResolver::latestStatus($user->info), $residenceStatuses, true)
            );
        }

        return $users
            ->sort(fn(User $a, User $b) => strnatcmp((string) $a->staff_no, (string) $b->staff_no) ?: $a->id <=> $b->id)
            ->values();
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    private function buildWindows(Carbon $from, int $dayCount): array
    {
        return array_map(
            fn(int $offset) => [
                'start' => $from->clone()->addDays($offset)->toDateString(),
                'end' => $from
                    ->clone()
                    ->addDays($offset + self::DAYS_PER_WINDOW - 1)
                    ->toDateString(),
            ],
            $this->windowOffsets($dayCount)
        );
    }

    /**
     * @param Collection<int, User> $users
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(Collection $users, Carbon $from, int $dayCount): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        $userIds = $users->pluck('id')->all();
        $to = $from
            ->clone()
            ->addDays($dayCount - 1)
            ->endOfDay();
        $workHoursByUser = WorkHelper::getWorkHours($userIds, $from, $to);
        $systemConfig = SystemConfig::query()->first();
        $userConfigs = UserConfig::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        return $users->map(function (User $user) use ($workHoursByUser, $systemConfig, $userConfigs, $from, $dayCount) {
            $userConfig = $userConfigs->get($user->id);
            $days = WorkHelper::getDailyHours($workHoursByUser[$user->id] ?? [], $from, $dayCount);
            foreach ($days as $index => $day) {
                $date = Carbon::parse($day['date']);
                $days[$index]['max_hour_per_week'] = WorkHelper::getMaxHourPerWeekOnDate(
                    $systemConfig,
                    $userConfig,
                    $date
                );
                $days[$index]['is_custom_limit'] = WorkHelper::isUserMaxHourPerWeekApplied($userConfig, $date);
            }

            $windows = array_map(
                fn(int $offset) => $this->summarizeDays(array_slice($days, $offset, self::DAYS_PER_WINDOW)),
                $this->windowOffsets($dayCount)
            );
            $status = collect($windows)
                ->map(fn(array $summary) => WorkLoadStatus::from($summary['status']))
                ->sortByDesc(fn(WorkLoadStatus $status) => $status->severity())
                ->first();

            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->short_name,
                    'staff_no' => $user->staff_no,
                    'profile_picture' => $user->profile_picture,
                ],
                'residence_status' => ResidenceSlotResolver::latestStatus($user->info),
                'days' => array_map(
                    fn(array $day) => [
                        'date' => $day['date'],
                        'hours' => round($day['hours'], 2),
                        'actual_hours' => round($day['actual_hours'], 2),
                        'scheduled_hours' => round($day['scheduled_hours'], 2),
                    ],
                    $days
                ),
                'weeks' => $windows,
                'status' => $status->value,
            ];
        });
    }

    /**
     * @param list<array{date: string, hours: float, actual_hours: float, scheduled_hours: float, max_hour_per_week: int, is_custom_limit: bool}> $days
     * @return array<string, mixed>
     */
    private function summarizeDays(array $days): array
    {
        $totalHours = array_sum(array_column($days, 'hours'));
        $dailyLimits = array_column($days, 'max_hour_per_week');
        $maxHourPerWeek = min($dailyLimits);
        $bindingDays = array_filter($days, fn(array $day) => $day['max_hour_per_week'] === $maxHourPerWeek);

        return [
            'start' => $days[0]['date'],
            'end' => $days[count($days) - 1]['date'],
            'total_hours' => round($totalHours, 2),
            'actual_hours' => round(array_sum(array_column($days, 'actual_hours')), 2),
            'scheduled_hours' => round(array_sum(array_column($days, 'scheduled_hours')), 2),
            'max_hour_per_week' => $maxHourPerWeek,
            'is_custom_limit' => in_array(true, array_column($bindingDays, 'is_custom_limit'), true),
            'is_limit_changed' => count(array_unique($dailyLimits)) > 1,
            'status' => WorkLoadStatus::fromHours($totalHours, $maxHourPerWeek)->value,
        ];
    }
}
