<?php

namespace App\Observers\Group;

use App\Actions\Admin\Group\RecomputeUserMembershipsAction;
use App\Enums\GroupKind;
use App\Models\User;

class UserObserver
{
    public function __construct(private readonly RecomputeUserMembershipsAction $recomputeUserMemberships) {}

    public function updated(User $user): void
    {
        if (!$user->wasChanged('is_disabled') || $user->is_disabled) {
            return;
        }

        ($this->recomputeUserMemberships)($user, GroupKind::systemKinds());
    }
}
