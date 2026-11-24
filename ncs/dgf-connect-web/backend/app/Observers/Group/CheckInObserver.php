<?php

namespace App\Observers\Group;

use App\Actions\Admin\Group\RecomputeUserMembershipsAction;
use App\Enums\GroupKind;
use App\Models\CheckIn;
use App\Models\User;

class CheckInObserver
{
    public function __construct(private readonly RecomputeUserMembershipsAction $recomputeUserMemberships) {}

    public function created(CheckIn $checkIn): void
    {
        if (!$this->isCompletedShiftSignal($checkIn, false)) {
            return;
        }

        $this->recomputeMemberships($checkIn);
    }

    public function updated(CheckIn $checkIn): void
    {
        if (!$this->isCompletedShiftSignal($checkIn, true)) {
            return;
        }

        $this->recomputeMemberships($checkIn);
    }

    private function isCompletedShiftSignal(CheckIn $checkIn, bool $requiresFreshCheckout): bool
    {
        if ($checkIn->check_out_time === null) {
            return false;
        }

        if ($requiresFreshCheckout && !$checkIn->wasChanged('check_out_time')) {
            return false;
        }

        $checkIn->loadMissing('shift', 'shiftUser');

        return $checkIn->shift !== null && $checkIn->shiftUser !== null && $checkIn->shiftUser->is_absent === false;
    }

    private function recomputeMemberships(CheckIn $checkIn): void
    {
        $user = User::query()->find($checkIn->user_id);
        if ($user === null) {
            return;
        }

        $checkIn->loadMissing('shift');
        $workplaceId = $checkIn->shift?->workplace_id;
        if ($workplaceId === null) {
            return;
        }

        ($this->recomputeUserMemberships)($user, [GroupKind::Worktime, GroupKind::WorkplaceSeniority], $workplaceId);
    }
}
