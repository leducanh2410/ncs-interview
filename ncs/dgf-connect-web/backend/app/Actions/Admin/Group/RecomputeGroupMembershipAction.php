<?php

namespace App\Actions\Admin\Group;

use App\Actions\Admin\Group\Strategies\RuleBasedStrategy;
use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Exceptions\RuleBasedGroupTooBroadException;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

class RecomputeGroupMembershipAction
{
    /**
     * Max active-user coverage allowed for a rule-based group. Above this the
     * group is rejected as too broad (admin-input cap, not a DB limit).
     */
    public const COVERAGE_THRESHOLD_PERCENT = 70;

    /**
     * Chunk size for bulk insert/whereIn-delete to stay under MySQL's 65,535
     * placeholder ceiling (insert uses 4 placeholders per row).
     */
    private const WRITE_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly RuleBasedStrategy $ruleBasedStrategy,
        private readonly SyncUserGroupChatParticipantsAction $syncChat
    ) {}

    /**
     * Replace every user_groups row for the given group with the freshly computed set.
     *
     * Only meaningful for rule-based groups in v1; system groups are recomputed per-user
     * by RecomputeUserMembershipsAction, and manual groups are admin-curated.
     *
     * When $enforceCoverageCap is true (default), throws RuleBasedGroupTooBroadException
     * if the rule matches more than COVERAGE_THRESHOLD_PERCENT of active users. Pass
     * false from backfill / migration paths where existing data must replay regardless.
     */
    public function __invoke(Group $group, bool $enforceCoverageCap = true): void
    {
        if ($group->kind !== GroupKind::RuleBased) {
            return;
        }

        $desiredIds = array_map('intval', $this->ruleBasedStrategy->userIdsForGroup($group)->all());

        if ($enforceCoverageCap) {
            $this->assertWithinCoverageCap($desiredIds);
        }

        DB::transaction(function () use ($group, $desiredIds) {
            $currentIds = array_map(
                'intval',
                UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->all()
            );

            $toDelete = array_values(array_diff($currentIds, $desiredIds));
            $toInsertIds = array_values(array_diff($desiredIds, $currentIds));

            foreach (array_chunk($toDelete, self::WRITE_CHUNK_SIZE) as $deleteChunk) {
                UserGroup::query()->where('group_id', $group->id)->whereIn('user_id', $deleteChunk)->delete();
            }

            if (!empty($toInsertIds)) {
                $now = now();
                foreach (array_chunk($toInsertIds, self::WRITE_CHUNK_SIZE) as $insertChunk) {
                    $rows = array_map(
                        fn(int $uid) => [
                            'group_id' => $group->id,
                            'user_id' => $uid,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $insertChunk
                    );
                    UserGroup::query()->insert($rows);
                }
            }
        });

        ($this->syncChat)($group->id);
    }

    private function assertWithinCoverageCap(array $desiredIds): void
    {
        $matchCount = count($desiredIds);
        if ($matchCount === 0) {
            return;
        }

        $activeUserCount = User::query()->where('is_disabled', false)->count();
        if ($activeUserCount <= 0) {
            return;
        }

        if (($matchCount / $activeUserCount) * 100 > self::COVERAGE_THRESHOLD_PERCENT) {
            throw new RuleBasedGroupTooBroadException(
                matchCount: $matchCount,
                activeUserCount: $activeUserCount,
                thresholdPercent: self::COVERAGE_THRESHOLD_PERCENT
            );
        }
    }
}
