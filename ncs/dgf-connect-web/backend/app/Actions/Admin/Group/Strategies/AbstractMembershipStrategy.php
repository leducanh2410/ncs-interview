<?php

namespace App\Actions\Admin\Group\Strategies;

use App\Actions\Admin\Group\Contracts\MembershipStrategy;
use App\Models\User;
use Illuminate\Support\Collection;

abstract class AbstractMembershipStrategy implements MembershipStrategy
{
    final public function groupsForUser(User $user, ?int $workplaceId = null): Collection
    {
        if ($user->is_disabled) {
            return new Collection();
        }

        return $this->compute($user, $workplaceId);
    }

    /**
     * @return Collection<int, \App\Models\Group>
     */
    abstract protected function compute(User $user, ?int $workplaceId): Collection;
}
