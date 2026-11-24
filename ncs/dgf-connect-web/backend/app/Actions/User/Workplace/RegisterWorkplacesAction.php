<?php

namespace App\Actions\User\Workplace;

use App\Actions\ActionResult;
use App\Actions\Workplace\AssertTestMembershipMatchAction;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\ShiftUser;
use App\Models\Workplace;
use App\Models\WorkplaceUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RegisterWorkplacesAction
{
    public function __construct(private readonly AssertTestMembershipMatchAction $assertTestMembershipMatch) {}

    public function __invoke(int $userId, array $workplaceIds): ActionResult
    {
        return DB::transaction(function () use ($userId, $workplaceIds) {
            if (!$this->assertTestMembershipMatch->__invoke([$userId], $workplaceIds)) {
                return new ActionResult(error: ErrorCode::WorkplaceTestMembershipMismatch, status: 400);
            }

            $currentWorkplaceIds = WorkplaceUser::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->pluck('workplace_id')
                ->toArray();

            $removingWorkplaceIds = array_diff($currentWorkplaceIds, $workplaceIds);
            $addingWorkplaceIds = array_diff($workplaceIds, $currentWorkplaceIds);

            // Workplaces that require an interview/exam are admin-managed: the user can
            // neither join nor leave them on their own, so any change touching one is
            // rejected instead of being silently ignored.
            $changingWorkplaceIds = array_merge($removingWorkplaceIds, $addingWorkplaceIds);
            if (!empty($changingWorkplaceIds)) {
                $hasSelfRegisterDisabled = Workplace::query()
                    ->whereIn('id', $changingWorkplaceIds)
                    ->where('is_self_register_disabled', true)
                    ->exists();

                if ($hasSelfRegisterDisabled) {
                    return new ActionResult(error: ErrorCode::WorkplaceSelfRegistrationNotAllowed, status: 400);
                }
            }

            if (!empty($removingWorkplaceIds)) {
                $today = Carbon::today()->format('Y-m-d');
                $hasActiveSchedule = ShiftUser::query()
                    ->where('user_id', $userId)
                    ->whereIn('status', [ShiftUserStatus::Pending->value, ShiftUserStatus::Approved->value])
                    ->whereHas('shift', function ($query) use ($removingWorkplaceIds, $today) {
                        $query->whereIn('workplace_id', $removingWorkplaceIds)->where('date', '>=', $today);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveSchedule) {
                    return new ActionResult(error: ErrorCode::WorkplaceCannotExitWithActiveSchedule, status: 400);
                }
            }

            WorkplaceUser::query()->where('user_id', $userId)->delete();
            foreach ($workplaceIds as $workplaceId) {
                WorkplaceUser::query()->create([
                    'user_id' => $userId,
                    'workplace_id' => $workplaceId,
                ]);
            }

            return new ActionResult();
        });
    }
}
