<?php

namespace App\Actions\Manage\Notification;

use App\Actions\Admin\Forecast\FindNearbyWorkplacesAction;
use App\Actions\Admin\Group\RecomputeFrequentUrgentWorkerGroupAction;
use App\Models\CheckIn;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use App\ValueObjects\RecruitmentCriteria;
use Illuminate\Support\Facades\DB;

/**
 * Turns a recruitment notification's criteria into the concrete list of people it is sent to.
 *
 * Runs at send time rather than at creation time. Two of the filters — experience and
 * shift eligibility — depend on data that moves continuously (a candidate's recent
 * attendance, their surrounding schedule), so a list resolved when the admin clicked save
 * would already be wrong by the time a scheduled notification actually goes out.
 *
 * The pipeline widens, then narrows:
 *
 *   1. refresh the frequent-urgent group, if it was opted into
 *   2. gather candidates: this workplace + nearby workplaces + that group
 *   3. filter by gender and experience
 *   4. drop anyone already on the shift
 *   5. drop anyone the shift-registration rules would reject
 *   6. persist the survivors, and how many there were
 *
 * Ticking "nearby workplaces" or "the urgent group" only widens step 2; nothing added there
 * escapes the filters in steps 3 to 5.
 */
class ResolveRecruitmentRecipientsAction
{
    /** Experience means having actually worked recently, within this many months. */
    private const EXPERIENCE_WINDOW_MONTHS = 3;

    public function __construct(
        private readonly RecomputeFrequentUrgentWorkerGroupAction $recomputeFrequentUrgentWorkerGroup,
        private readonly FindNearbyWorkplacesAction $findNearbyWorkplaces,
        private readonly FilterShiftEligibleUsersAction $filterShiftEligibleUsers,
        private readonly PersistNotificationRecipientsAction $persistRecipients
    ) {}

    /**
     * @return array<int, int> the resolved recipient user ids
     */
    public function __invoke(Notification $notification): array
    {
        $criteria = RecruitmentCriteria::fromNotification($notification);

        $shift = Shift::query()->find($criteria->shiftId);
        if ($shift === null) {
            $this->persist($notification, []);

            return [];
        }

        $candidateIds = $this->buildCandidatePool($criteria);
        $candidateIds = $this->applyCriteriaFilters($candidateIds, $criteria);
        $candidateIds = $this->excludeAlreadyOnShift($candidateIds, $shift);

        // Applied last: it is by far the most expensive filter, so it runs against the
        // smallest set the cheaper filters can produce.
        $recipientIds = ($this->filterShiftEligibleUsers)($shift, $candidateIds);

        $this->persist($notification, $recipientIds);

        return $recipientIds;
    }

    /**
     * @return array<int, int>
     */
    private function buildCandidatePool(RecruitmentCriteria $criteria): array
    {
        $targetWorkplace = Workplace::query()->find($criteria->workplaceId);
        $workplaceIds = [$criteria->workplaceId];

        if ($criteria->includeNearbyWorkplaces && $criteria->nearbyRadiusKm !== null && $targetWorkplace !== null) {
            $nearbyIds = ($this->findNearbyWorkplaces)($targetWorkplace, $criteria->nearbyRadiusKm)->pluck('id')->all();

            if (!empty($nearbyIds)) {
                // The search is purely geographic and would otherwise happily pull a test
                // workplace into a production campaign's audience (or vice versa), breaking
                // the test/production boundary that workplace assignment normally enforces.
                $nearbyIds = Workplace::query()
                    ->whereIn('id', $nearbyIds)
                    ->where('is_test', (bool) $targetWorkplace->is_test)
                    ->pluck('id')
                    ->all();
            }

            $workplaceIds = array_merge($workplaceIds, $nearbyIds);
        }

        $userIds = DB::table('workplace_users')->whereIn('workplace_id', $workplaceIds)->pluck('user_id')->all();

        if ($criteria->includeFrequentUrgentGroup) {
            // Recomputed inline: membership expires as the trailing window slides, and the
            // daily job may not have run since the last qualifying shift.
            $group = ($this->recomputeFrequentUrgentWorkerGroup)();

            // Deliberately not restricted by distance. These employees have shown they will
            // travel for urgent work, which is the whole reason for singling them out.
            $userIds = array_merge(
                $userIds,
                UserGroup::query()->where('group_id', $group->id)->pluck('user_id')->all()
            );
        }

        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if (empty($userIds)) {
            return [];
        }

        $query = User::query()->whereIn('id', $userIds)->where('is_disabled', false);

        if ($targetWorkplace !== null) {
            // The frequent-urgent group is global, so it can mix test and production users;
            // re-assert the same test/production boundary here regardless of which source
            // (nearby workplace or the group) a candidate came from.
            $query->where('is_test', (bool) $targetWorkplace->is_test);
        }

        return array_map('intval', $query->pluck('id')->all());
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    private function applyCriteriaFilters(array $userIds, RecruitmentCriteria $criteria): array
    {
        if (empty($userIds)) {
            return [];
        }

        $query = User::query()->whereIn('id', $userIds);

        $matchingGenders = $criteria->genderTarget->matchingGenders();
        if ($matchingGenders !== null) {
            // Unrecorded gender always passes. Profile data is patchy, and excluding those
            // employees would silently shrink the audience for a shift that needs filling.
            // Grouped so the alternatives cannot escape the surrounding whereIn: a missing
            // profile row and a null gender both count as unrecorded.
            $query->where(function ($outer) use ($matchingGenders) {
                $outer
                    ->whereHas('info', fn($info) => $info->whereIn('gender', $matchingGenders))
                    ->orWhereHas('info', fn($info) => $info->whereNull('gender'))
                    ->orWhereDoesntHave('info');
            });
        }

        if ($criteria->requiresExperience) {
            $windowStart = now()->subMonthsNoOverflow(self::EXPERIENCE_WINDOW_MONTHS)->toDateString();

            // Experience means having turned up, not having been rostered: a check-in row is
            // the evidence, an assignment is only an intention.
            $experiencedIds = CheckIn::query()
                ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
                ->whereIn('check_ins.user_id', $userIds)
                ->where('shifts.date', '>=', $windowStart)
                ->distinct()
                ->pluck('check_ins.user_id')
                ->all();

            $query->whereIn('id', array_map('intval', $experiencedIds));
        }

        return array_map('intval', $query->pluck('id')->all());
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    private function excludeAlreadyOnShift(array $userIds, Shift $shift): array
    {
        if (empty($userIds)) {
            return [];
        }

        $alreadyOnShift = ShiftUser::query()
            ->where('shift_id', $shift->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map('intval')
            ->all();

        return array_values(array_diff($userIds, $alreadyOnShift));
    }

    /**
     * @param array<int, int> $recipientIds
     */
    private function persist(Notification $notification, array $recipientIds): void
    {
        ($this->persistRecipients)($notification, $recipientIds);
    }
}
