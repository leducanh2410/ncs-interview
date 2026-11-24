<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkplaceUser;

class GetShiftWeeklyWorkLoadAction
{
    public function __construct(private readonly CalculateShiftWeeklyWorkLoadAction $calculateWeeklyWorkLoad) {}

    /**
     * @param list<int> $userIds
     */
    public function __invoke(User $actor, Shift $shift, array $userIds): ActionResult
    {
        if (!$actor->isAbleTo(PermissionName::ShiftList->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $requestedUserIds = array_values(array_unique(array_map('intval', $userIds)));
        $shiftUserIds = $shift->shiftUsers()->whereIn('user_id', $requestedUserIds)->pluck('user_id');
        $workplaceMemberIds = WorkplaceUser::query()
            ->where('workplace_id', $shift->workplace_id)
            ->whereIn('user_id', $requestedUserIds)
            ->pluck('user_id');
        $visibleUserIds = $shiftUserIds->merge($workplaceMemberIds)->unique()->values()->all();

        $workLoads = ($this->calculateWeeklyWorkLoad)($shift, $visibleUserIds);

        return new ActionResult(
            data: array_values(array_map(CalculateShiftWeeklyWorkLoadAction::roundForResponse(...), $workLoads))
        );
    }
}
