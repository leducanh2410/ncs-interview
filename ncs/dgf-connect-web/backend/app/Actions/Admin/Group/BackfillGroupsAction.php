<?php

namespace App\Actions\Admin\Group;

use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Enums\SourceTier;
use App\Models\Group;
use App\Models\User;
use App\Models\Workplace;
use App\ValueObjects\GroupIdentity;
use Closure;

class BackfillGroupsAction
{
    public function __construct(
        private readonly EnsureSystemGroupsForWorkplaceAction $ensureSystemGroupsForWorkplace,
        private readonly RecomputeUserMembershipsAction $recomputeUserMemberships,
        private readonly RecomputeGroupMembershipAction $recomputeGroupMembership,
        private readonly RecomputeFrequentUrgentWorkerGroupAction $recomputeFrequentUrgentWorkerGroup,
        private readonly SyncUserGroupChatParticipantsAction $syncChat
    ) {}

    /**
     * Rebuild every system group row and refresh user_groups for every non-locked user.
     *
     * Idempotent: re-running against an already-backfilled DB yields zero net changes.
     * Locked users are skipped (their existing rows are preserved per design).
     *
     * @param  Closure(string, int=, int=): void|null  $progress  Callback invoked as ($message, $current, $total).
     * @return array{workplaces:int, global_tier_groups:int, users:int, rule_based_groups:int, frequent_urgent_workers:int}
     */
    public function __invoke(int $chunkSize = 500, ?Closure $progress = null): array
    {
        $progress ??= static function (): void {};

        $workplaces = $this->ensureWorkplaceSystemGroups($progress);
        $globalTierGroups = $this->ensureGlobalTierGroups();
        $users = $this->recomputeAllUsers($chunkSize, $progress);
        $ruleBasedGroups = $this->recomputeRuleBasedGroups($progress);
        $frequentUrgentWorkers = $this->backfillFrequentUrgentWorkerGroup($progress);

        return [
            'workplaces' => $workplaces,
            'global_tier_groups' => $globalTierGroups,
            'users' => $users,
            'rule_based_groups' => $ruleBasedGroups,
            'frequent_urgent_workers' => $frequentUrgentWorkers,
        ];
    }

    private function ensureWorkplaceSystemGroups(Closure $progress): int
    {
        $count = 0;
        Workplace::query()
            ->orderBy('id')
            ->each(function (Workplace $workplace) use (&$count, $progress) {
                ($this->ensureSystemGroupsForWorkplace)($workplace);
                $count++;
                $progress('workplace', $count);
            });

        return $count;
    }

    private function ensureGlobalTierGroups(): int
    {
        $count = 0;

        foreach (SourceTier::worktimeTiers() as $tier) {
            Group::firstOrCreateByIdentity(new GroupIdentity(kind: GroupKind::Worktime, sourceTier: $tier));
            $count++;
        }

        return $count;
    }

    private function recomputeAllUsers(int $chunkSize, Closure $progress): int
    {
        $total = User::query()->where('is_disabled', false)->count();
        $processed = 0;
        $affectedGroupIds = [];

        User::query()
            ->where('is_disabled', false)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$processed, &$affectedGroupIds, $total, $progress) {
                foreach ($users as $user) {
                    // Skip the per-group chat sync during the per-user pass: each recompute only
                    // reports the groups whose membership changed, so a group is re-synced after
                    // the whole user set is processed instead of once per join. Without this, a
                    // group of N members would be re-synced N times here — re-querying its full
                    // roster and dispatching broadcasts N times (O(N^2) work per conversation).
                    $affectedGroupIds = array_merge(
                        $affectedGroupIds,
                        ($this->recomputeUserMemberships)($user, syncChat: false)
                    );
                    $processed++;
                    $progress('user', $processed, $total);
                }
            });

        // Single sync pass per affected group. Sync is idempotent and no-ops for groups without
        // an open conversation, so duplicates collapse to one chat participant reconciliation.
        foreach (array_values(array_unique(array_map('intval', $affectedGroupIds))) as $groupId) {
            ($this->syncChat)($groupId);
        }

        return $processed;
    }

    /**
     * The frequent-urgent-worker group is computed set-based over everyone at once rather
     * than per user, so it sits outside the per-user pass above and needs its own step.
     */
    private function backfillFrequentUrgentWorkerGroup(Closure $progress): int
    {
        $group = ($this->recomputeFrequentUrgentWorkerGroup)();
        $progress('frequent_urgent_worker', 1, 1);

        return (int) $group->users()->count();
    }

    private function recomputeRuleBasedGroups(Closure $progress): int
    {
        $count = 0;
        Group::query()
            ->where('kind', GroupKind::RuleBased->value)
            ->whereNotNull('predicate')
            ->orderBy('id')
            ->each(function (Group $group) use (&$count, $progress) {
                ($this->recomputeGroupMembership)($group, enforceCoverageCap: false);
                $count++;
                $progress('rule_based', $count);
            });

        return $count;
    }
}
