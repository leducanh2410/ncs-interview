<?php

namespace App\Actions\Admin\Group\Strategies;

use App\Enums\GroupKind;
use App\Models\CheckIn;
use App\Models\Group;
use App\Models\User;
use App\ValueObjects\GroupIdentity;
use App\ValueObjects\GroupTierConfig;
use Illuminate\Support\Collection;

class WorkplaceSeniorityStrategy extends AbstractMembershipStrategy
{
    public function __construct(private readonly GroupTierConfig $tierConfig = new GroupTierConfig()) {}

    public function kind(): GroupKind
    {
        return GroupKind::WorkplaceSeniority;
    }

    protected function compute(User $user, ?int $workplaceId): Collection
    {
        $workplaceIds = $user
            ->workplaces()
            ->when($workplaceId !== null, fn($q) => $q->where('workplaces.id', $workplaceId))
            ->pluck('workplaces.id');

        if ($workplaceIds->isEmpty()) {
            return new Collection();
        }

        $counts = CheckIn::query()
            ->join('shift_users', 'shift_users.id', '=', 'check_ins.shift_user_id')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->where('check_ins.user_id', $user->id)
            ->whereNotNull('check_ins.check_out_time')
            ->where('shift_users.is_absent', false)
            ->whereIn('shifts.workplace_id', $workplaceIds)
            ->selectRaw('shifts.workplace_id as workplace_id, COUNT(*) as cnt')
            ->groupBy('shifts.workplace_id')
            ->pluck('cnt', 'workplace_id');

        return $workplaceIds
            ->map(function (int $id) use ($counts) {
                $count = (int) ($counts[$id] ?? 0);
                $tier = $this->tierConfig->workplaceSeniorityTier($count);

                return Group::firstOrCreateByIdentity(
                    new GroupIdentity(kind: GroupKind::WorkplaceSeniority, sourceWorkplaceId: $id, sourceTier: $tier)
                );
            })
            ->values();
    }
}
