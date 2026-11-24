<?php

namespace App\Observers\Group;

use App\Actions\Admin\Group\RecomputeUserMembershipsAction;
use App\Actions\Admin\Group\Strategies\RuleBasedStrategy;
use App\Enums\GroupKind;
use App\Models\User;
use App\Models\UserInfo;

class UserInfoObserver
{
    public function __construct(private readonly RecomputeUserMembershipsAction $recomputeUserMemberships) {}

    public function saved(UserInfo $userInfo): void
    {
        $kinds = [];

        if ($this->hasRuleBasedFieldChanges($userInfo)) {
            $kinds[] = GroupKind::RuleBased;
        }

        if (empty($kinds)) {
            return;
        }

        $user = User::query()->find($userInfo->user_id);
        if ($user === null) {
            return;
        }

        ($this->recomputeUserMemberships)($user, $kinds);
    }

    private function hasRuleBasedFieldChanges(UserInfo $userInfo): bool
    {
        return !empty(array_intersect(array_keys($userInfo->getChanges()), RuleBasedStrategy::ALLOWED_FIELDS));
    }
}
