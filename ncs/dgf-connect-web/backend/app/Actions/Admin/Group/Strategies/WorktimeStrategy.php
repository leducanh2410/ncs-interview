<?php

namespace App\Actions\Admin\Group\Strategies;

use App\Enums\GroupKind;
use App\Enums\SourceTier;
use App\Models\CheckIn;
use App\Models\Group;
use App\Models\User;
use App\ValueObjects\GroupIdentity;
use App\ValueObjects\GroupTierConfig;
use Illuminate\Support\Collection;

class WorktimeStrategy extends AbstractMembershipStrategy
{
    public function __construct(private readonly GroupTierConfig $tierConfig = new GroupTierConfig()) {}

    public function kind(): GroupKind
    {
        return GroupKind::Worktime;
    }

    protected function compute(User $user, ?int $workplaceId): Collection
    {
        $rows = CheckIn::query()
            ->join('shift_users', 'shift_users.id', '=', 'check_ins.shift_user_id')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->join('workplace_shifts', 'workplace_shifts.id', '=', 'shifts.workplace_shift_id')
            ->where('check_ins.user_id', $user->id)
            ->whereNotNull('check_ins.check_out_time')
            ->where('shift_users.is_absent', false)
            ->orderBy('shifts.date')
            ->orderBy('check_ins.check_in_time')
            ->get(['workplace_shifts.start_time', 'shifts.date']);

        if ($rows->isEmpty()) {
            return new Collection();
        }

        $counts = [
            SourceTier::Morning->value => 0,
            SourceTier::Afternoon->value => 0,
            SourceTier::Night->value => 0,
        ];

        $latestTier = null;
        foreach ($rows as $row) {
            $hour = (int) mb_substr((string) $row->start_time, 0, 2);
            $tier = $this->tierConfig->worktimeTier($hour);
            $counts[$tier->value]++;
            $latestTier = $tier;
        }

        $maxCount = max($counts);
        $winners = array_keys(array_filter($counts, fn($c) => $c === $maxCount));

        $tier =
            count($winners) === 1
                ? SourceTier::from($winners[0])
                : (in_array($latestTier?->value, $winners, true)
                    ? $latestTier
                    : SourceTier::from($winners[0]));

        $group = Group::firstOrCreateByIdentity(new GroupIdentity(kind: GroupKind::Worktime, sourceTier: $tier));

        return new Collection([$group]);
    }
}
