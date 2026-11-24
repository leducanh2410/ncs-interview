<?php

namespace App\Actions\Admin\Group;

use App\Actions\Admin\Group\Contracts\MembershipStrategy;
use App\Actions\Admin\Group\Strategies\RuleBasedStrategy;
use App\Actions\Admin\Group\Strategies\WorkplaceCurrentStrategy;
use App\Actions\Admin\Group\Strategies\WorkplaceSeniorityStrategy;
use App\Actions\Admin\Group\Strategies\WorktimeStrategy;
use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

class RecomputeUserMembershipsAction
{
    /** @var array<string, MembershipStrategy> */
    private array $strategies;

    public function __construct(
        WorkplaceCurrentStrategy $workplaceCurrent,
        WorkplaceSeniorityStrategy $workplaceSeniority,
        WorktimeStrategy $worktime,
        RuleBasedStrategy $ruleBased,
        private readonly SyncUserGroupChatParticipantsAction $syncChat
    ) {
        $strategies = [$workplaceCurrent, $workplaceSeniority, $worktime, $ruleBased];

        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->kind()->value] = $strategy;
        }
    }

    /**
     * Recompute membership for the given user across the listed kinds.
     *
     * @param  array<int, GroupKind|string>|null  $kinds  Defaults to every derivable kind (system + rule_based).
     * @param  int|null  $workplaceId  Limits per-workplace kinds to a single workplace; ignored for global kinds.
     * @param  bool  $syncChat  When true (default), sync the user_group chat conversation of every
     *                          affected group inline. Pass false from callers that rebuild many
     *                          users at once (e.g. BackfillGroupsAction) and batch a single sync
     *                          per affected group afterwards — see the return value.
     * @return array<int, int>  IDs of the groups whose membership changed (regardless of $syncChat).
     */
    public function __invoke(User $user, ?array $kinds = null, ?int $workplaceId = null, bool $syncChat = true): array
    {
        if ($user->is_disabled) {
            return [];
        }

        $kindObjects = $this->resolveKinds($kinds);
        if (empty($kindObjects)) {
            return [];
        }

        $affectedGroupIds = [];

        DB::transaction(function () use ($user, $kindObjects, $workplaceId, &$affectedGroupIds) {
            $desiredGroupIds = [];
            $perWorkplaceKindValues = [];
            $globalKindValues = [];

            foreach ($kindObjects as $kind) {
                $strategy = $this->strategies[$kind->value] ?? null;
                if ($strategy === null) {
                    continue;
                }

                foreach ($strategy->groupsForUser($user, $workplaceId) as $group) {
                    $desiredGroupIds[$group->id] = true;
                }

                if ($this->isPerWorkplaceKind($kind)) {
                    $perWorkplaceKindValues[] = $kind->value;
                } else {
                    $globalKindValues[] = $kind->value;
                }
            }

            $currentGroupIds = UserGroup::query()
                ->join('groups', 'groups.id', '=', 'user_groups.group_id')
                ->where('user_groups.user_id', $user->id)
                ->where(function ($q) use ($globalKindValues, $perWorkplaceKindValues, $workplaceId) {
                    if (!empty($globalKindValues)) {
                        $q->orWhereIn('groups.kind', $globalKindValues);
                    }
                    if (!empty($perWorkplaceKindValues)) {
                        $q->orWhere(function ($q2) use ($perWorkplaceKindValues, $workplaceId) {
                            $q2->whereIn('groups.kind', $perWorkplaceKindValues);
                            if ($workplaceId !== null) {
                                $q2->where('groups.source_workplace_id', $workplaceId);
                            }
                        });
                    }
                })
                ->pluck('user_groups.group_id')
                ->all();

            $desired = array_keys($desiredGroupIds);
            $toDelete = array_values(array_diff($currentGroupIds, $desired));
            $toInsert = array_values(array_diff($desired, $currentGroupIds));

            // Groups the user joined or left here — their user_group chat conversations (if any)
            // must be re-synced once this transaction commits.
            $affectedGroupIds = array_values(array_unique(array_merge($toDelete, $toInsert)));

            if (!empty($toDelete)) {
                UserGroup::query()->where('user_id', $user->id)->whereIn('group_id', $toDelete)->delete();
            }

            if (!empty($toInsert)) {
                $now = now();
                $rows = array_map(
                    fn(int $gid) => [
                        'user_id' => $user->id,
                        'group_id' => $gid,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $toInsert
                );
                UserGroup::query()->insert($rows);
            }
        });

        // Sync chat participants for every group whose membership changed. Many production paths
        // (UserInfo/CheckIn/WorkplaceUser/User observers and per-user backfill) reach group
        // membership only through here, so without this their user_group conversations would drift
        // until a full-group recompute. The sync no-ops for groups without an open conversation.
        //
        // Callers that rebuild many users at once pass $syncChat = false and batch a single sync
        // per affected group after their loop (see BackfillGroupsAction) — otherwise a group with
        // N members would be re-synced once per join, re-querying its whole roster N times and
        // producing a burst of redundant broadcasts.
        if ($syncChat) {
            foreach ($affectedGroupIds as $groupId) {
                ($this->syncChat)((int) $groupId);
            }
        }

        return $affectedGroupIds;
    }

    /**
     * @param  array<int, GroupKind|string>|null  $kinds
     * @return array<int, GroupKind>
     */
    private function resolveKinds(?array $kinds): array
    {
        if ($kinds === null) {
            return array_values(array_map(fn(MembershipStrategy $s) => $s->kind(), $this->strategies));
        }

        $resolved = [];
        foreach ($kinds as $kind) {
            $resolved[] = $kind instanceof GroupKind ? $kind : GroupKind::from($kind);
        }

        return $resolved;
    }

    private function isPerWorkplaceKind(GroupKind $kind): bool
    {
        return in_array($kind, [GroupKind::WorkplaceCurrent, GroupKind::WorkplaceSeniority], true);
    }
}
