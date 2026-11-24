<?php

namespace App\Actions\Admin\Group\Strategies;

use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\User;
use App\ValueObjects\GroupIdentity;
use Illuminate\Support\Collection;

class WorkplaceCurrentStrategy extends AbstractMembershipStrategy
{
    public function kind(): GroupKind
    {
        return GroupKind::WorkplaceCurrent;
    }

    protected function compute(User $user, ?int $workplaceId): Collection
    {
        $workplaceIds = $user
            ->workplaces()
            ->when($workplaceId !== null, fn($q) => $q->where('workplaces.id', $workplaceId))
            ->pluck('workplaces.id');

        return $workplaceIds
            ->map(
                fn(int $id) => Group::firstOrCreateByIdentity(
                    new GroupIdentity(kind: GroupKind::WorkplaceCurrent, sourceWorkplaceId: $id)
                )
            )
            ->values();
    }
}
