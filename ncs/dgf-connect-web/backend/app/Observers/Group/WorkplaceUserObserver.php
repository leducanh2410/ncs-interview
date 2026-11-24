<?php

namespace App\Observers\Group;

use App\Actions\Admin\Group\RecomputeUserMembershipsAction;
use App\Enums\GroupKind;
use App\Models\User;
use App\Models\WorkplaceUser;

class WorkplaceUserObserver
{
    public function __construct(private readonly RecomputeUserMembershipsAction $recomputeUserMemberships) {}

    public function created(WorkplaceUser $workplaceUser): void
    {
        $this->recomputeMemberships($workplaceUser);
    }

    public function deleted(WorkplaceUser $workplaceUser): void
    {
        $this->recomputeMemberships($workplaceUser);
    }

    private function recomputeMemberships(WorkplaceUser $workplaceUser): void
    {
        $user = User::query()->find($workplaceUser->user_id);
        if ($user === null) {
            return;
        }

        ($this->recomputeUserMemberships)(
            $user,
            [GroupKind::WorkplaceCurrent, GroupKind::WorkplaceSeniority],
            $workplaceUser->workplace_id
        );
    }
}
