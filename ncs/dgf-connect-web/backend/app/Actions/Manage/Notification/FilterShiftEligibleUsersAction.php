<?php

namespace App\Actions\Manage\Notification;

use App\Actions\Manage\Shift\ValidatesShiftUser;
use App\Enums\UserPaperStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\UserConfig;
use Illuminate\Support\Carbon;

/**
 * Narrows a set of candidates to those who could actually register for a shift.
 *
 * Reuses WorkHelper::validateShiftRegister and ValidatesShiftUser::checkUserExpiration
 * unchanged rather than reimplementing any of their rules, so a recipient who taps through
 * the notification is never turned away by a gate that should have filtered them out
 * beforehand. Together these cover every check RegisterShiftAction applies per candidate:
 * shift overlap, minimum rest hours, maximum shifts per day, the weekly working-hour limit,
 * missing staff_no, a rejected profile, and expiring residence/passport/student documents.
 * On top of those it applies the CheckUserPaperStatus middleware's own rule — papers must be
 * Approved — which guards the endpoints the notification sends people to but sits outside the
 * action itself and so is not covered by reusing its validators alone.
 *
 * The weekly limit is resolved per employee (UserConfig -> SystemConfig -> config default),
 * so it is never a fixed 28 hours — anyone with their own configured limit is judged
 * against theirs.
 *
 * Two more RegisterShiftAction rules are not per-user at all — a shift that has already
 * ended, or one that already hit max_registered_temp — so they short-circuit the whole
 * candidate list up front instead of being evaluated per person.
 */
class FilterShiftEligibleUsersAction
{
    use ValidatesShiftUser;

    /**
     * Matches the window RegisterShiftAction feeds to the same validator: six days either
     * side, enough to cover every seven-day window the weekly limit is evaluated over.
     */
    private const SURROUNDING_DAYS = 6;

    /** Keeps one batch's work-hour result set to a sane size on a large candidate pool. */
    private const CHUNK_SIZE = 500;

    /**
     * @param array<int, int> $userIds
     * @return array<int, int> the subset that may register for this shift
     */
    public function __invoke(Shift $shift, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $shift->loadMissing('workplaceShift', 'workplace');
        [$startTime, $endTime] = $shift->getTimeRange();

        if (now()->gte($endTime)) {
            return [];
        }

        if ($shift->isMaxRegisteredTempReached()) {
            return [];
        }

        $rangeStart = $startTime->clone()->subDays(self::SURROUNDING_DAYS)->startOfDay();
        $rangeEnd = $startTime->clone()->addDays(self::SURROUNDING_DAYS)->endOfDay();
        $shiftDate = Carbon::parse($shift->date);

        $systemConfig = SystemConfig::query()->first();

        $eligible = [];
        foreach (array_chunk(array_values(array_unique($userIds)), self::CHUNK_SIZE) as $chunk) {
            $eligible = array_merge(
                $eligible,
                $this->filterChunk($shift, $chunk, $rangeStart, $rangeEnd, $shiftDate, $systemConfig)
            );
        }

        return $eligible;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    private function filterChunk(
        Shift $shift,
        array $userIds,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        Carbon $shiftDate,
        ?SystemConfig $systemConfig
    ): array {
        // One call for the whole chunk. getWorkHours already accepts a list of users and
        // returns the result keyed by user, so there is no reason to ask per candidate.
        $workHoursByUser = WorkHelper::getWorkHours($userIds, $rangeStart, $rangeEnd);

        // Likewise for the per-user limits and profile data: one query each, indexed by
        // user, instead of a lookup inside the loop.
        $userConfigs = UserConfig::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        // Approved papers, not merely "not rejected". checkUserExpiration only rejects the
        // Rejected status because it is shared with the admin assignment paths, where a
        // manager may legitimately roster someone whose documents are still being reviewed.
        // A notification recipient has no such operator behind them: both doors the push
        // leads to — GET /user/shifts/{shift} and POST /user/shifts/{shift}/register — sit
        // behind CheckUserPaperStatus, which demands Approved. An employee re-uploading
        // documents drops to wait_for_approval while keeping their staff_no and workplace,
        // so without this they would be pushed a shift that answers 403 either way.
        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('paper_status', UserPaperStatus::Approved->value)
            ->with('info')
            ->get()
            ->keyBy('id');

        $eligible = [];
        foreach ($userIds as $userId) {
            $user = $users->get($userId);
            if ($user === null || $this->checkUserExpiration($user, $shiftDate) !== null) {
                continue;
            }

            $error = WorkHelper::validateShiftRegister(
                $shift,
                $rangeStart,
                $workHoursByUser[$userId] ?? [],
                $systemConfig,
                $userConfigs->get($userId)
            );

            if ($error === null) {
                $eligible[] = $userId;
            }
        }

        return $eligible;
    }
}
