<?php

namespace App\Actions\Admin\Group;

use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\ShiftUser;
use App\Models\UserGroup;
use App\ValueObjects\GroupIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the single global "often takes urgent shifts" group.
 *
 * Computed set-based over the whole population rather than through the per-user
 * MembershipStrategy pattern the other system groups use. That pattern is event-driven:
 * an observer fires, one user is recomputed. Membership here expires purely through the
 * passage of time — an employee silently stops qualifying the day their second-most-recent
 * urgent shift slides out of the 90-day window, and no event accompanies that. A single
 * grouped query over everyone is both the fix for that decay and what makes an on-demand
 * refresh immediately before a send affordable.
 *
 * Qualification deliberately requires an actual check-in, not merely a flagged
 * registration: this group exists to find people worth calling in a hurry, and someone who
 * claimed an urgent shift and then did not turn up is evidence against, not for.
 */
class RecomputeFrequentUrgentWorkerGroupAction
{
    /** Trailing window, in days, over which qualifying urgent shifts are counted. */
    public const WINDOW_DAYS = 90;

    /** Checked-in urgent shifts required within the window to qualify. */
    public const MINIMUM_URGENT_SHIFTS = 2;

    /** Matches the chunking used by RecomputeGroupMembershipAction, for the same reason. */
    private const WRITE_CHUNK_SIZE = 1000;

    public function __construct(private readonly SyncUserGroupChatParticipantsAction $syncChat) {}

    public function __invoke(): Group
    {
        $group = Group::firstOrCreateByIdentity(new GroupIdentity(kind: GroupKind::FrequentUrgentWorker));

        $desiredIds = $this->qualifyingUserIds();

        DB::transaction(function () use ($group, $desiredIds) {
            $currentIds = array_map(
                'intval',
                UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->all()
            );

            $toDelete = array_values(array_diff($currentIds, $desiredIds));
            $toInsert = array_values(array_diff($desiredIds, $currentIds));

            foreach (array_chunk($toDelete, self::WRITE_CHUNK_SIZE) as $deleteChunk) {
                UserGroup::query()->where('group_id', $group->id)->whereIn('user_id', $deleteChunk)->delete();
            }

            if (!empty($toInsert)) {
                $now = now();
                foreach (array_chunk($toInsert, self::WRITE_CHUNK_SIZE) as $insertChunk) {
                    $rows = array_map(
                        fn(int $userId) => [
                            'group_id' => $group->id,
                            'user_id' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $insertChunk
                    );
                    // insertOrIgnore rather than insert: this action can run both from the
                    // daily 08:10 job and inline from a notification send, and both read
                    // $currentIds before either commits could race on the (group_id, user_id)
                    // unique key otherwise.
                    UserGroup::query()->insertOrIgnore($rows);
                }
            }
        });

        ($this->syncChat)($group->id);

        return $group;
    }

    /**
     * @return array<int, int>
     */
    private function qualifyingUserIds(): array
    {
        $windowStart = now()->subDays(self::WINDOW_DAYS)->toDateString();

        return array_map(
            'intval',
            ShiftUser::query()
                ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
                ->join('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
                ->join('users', 'users.id', '=', 'shift_users.user_id')
                ->where('shift_users.is_urgent_registration', true)
                ->where('users.is_disabled', false)
                ->where('shifts.date', '>=', $windowStart)
                ->groupBy('shift_users.user_id')
                ->havingRaw('COUNT(*) >= ?', [self::MINIMUM_URGENT_SHIFTS])
                ->pluck('shift_users.user_id')
                ->all()
        );
    }
}
