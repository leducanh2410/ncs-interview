<?php

namespace App\Actions\Admin\Group\Contracts;

use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

interface MembershipStrategy
{
    public function kind(): GroupKind;

    /**
     * Group rows the user should belong to under this kind.
     *
     * Returns an empty collection for locked users (insert short-circuit).
     * Lazily creates Group rows that don't yet exist for the resolved identities.
     *
     * @return Collection<int, Group>
     */
    public function groupsForUser(User $user, ?int $workplaceId = null): Collection;
}
