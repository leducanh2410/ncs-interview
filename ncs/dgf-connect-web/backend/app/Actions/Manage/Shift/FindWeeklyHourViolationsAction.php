<?php

namespace App\Actions\Manage\Shift;

use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\SystemConfig;
use App\Models\UserConfig;
use Illuminate\Support\Carbon;

class FindWeeklyHourViolationsAction
{
    /**
     * @param list<int> $userIds
     * @return array<int, float>
     */
    public function __invoke(Shift $shift, array $userIds, ?float $plannedHours = null): array
    {
        if (empty($userIds)) {
            return [];
        }

        $shiftDate = Carbon::parse($shift->date)->startOfDay();
        $rangeStart = $shiftDate->clone()->subDays(6);
        $rangeEnd = $shiftDate->clone()->addDays(6)->endOfDay();
        $workHoursByUser = WorkHelper::getWorkHours(
            $userIds,
            $rangeStart,
            $rangeEnd,
            $plannedHours === null ? null : $shift->id
        );
        $systemConfig = SystemConfig::query()->first();
        $userConfigs = UserConfig::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        $violations = [];
        foreach ($userIds as $userId) {
            $violation = WorkHelper::findMaxHourPerWeekViolation(
                $rangeStart,
                $workHoursByUser[$userId] ?? [],
                $plannedHours ?? 0.0,
                $systemConfig,
                $userConfigs->get($userId)
            );
            if ($violation !== null) {
                $violations[$userId] = $violation['over_hours'];
            }
        }

        return $violations;
    }
}
