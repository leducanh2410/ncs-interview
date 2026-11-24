<?php

namespace App\Actions\Common\Poll;

use App\Enums\PollTargetType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetPendingPollsAction
{
    /**
     * Get pending poll configs for a user.
     *
     * Eligibility rules:
     * - Global poll: user has non-null staff_no
     * - Workplace poll: user has non-null staff_no and has a record in workplace_users for the poll's workplace
     * - Specific-users poll: user has a record in poll_targets for the poll
     *
     * Excludes: inactive, expired, and already-responded polls
     */
    public function __invoke(User $user): Collection
    {
        if ($user->paper_status !== 'approved' || $user->staff_no === null) {
            return collect();
        }

        $respondedPollIds = PollResponse::query()->where('user_id', $user->id)->pluck('poll_id');

        $userWorkplaceIds = DB::table('workplace_users')->where('user_id', $user->id)->pluck('workplace_id')->toArray();

        return Poll::query()
            ->active()
            ->where('is_recurring', false)
            ->whereNotIn('id', $respondedPollIds)
            ->where(function ($query) use ($user, $userWorkplaceIds) {
                // Global polls
                $query
                    ->where('target_type', PollTargetType::GLOBAL)
                    // Workplace polls
                    ->orWhere(function ($q) use ($userWorkplaceIds) {
                        $q->where('target_type', PollTargetType::WORKPLACE)->whereIn('workplace_id', $userWorkplaceIds);
                    })
                    // Specific user polls
                    ->orWhere(function ($q) use ($user) {
                        $q->where('target_type', PollTargetType::SPECIFIC_USERS)->whereExists(function ($q2) use (
                            $user
                        ) {
                            $q2->select(DB::raw(1))
                                ->from('poll_targets')
                                ->whereColumn('poll_targets.poll_id', 'polls.id')
                                ->where('poll_targets.user_id', $user->id);
                        });
                    });
            })
            ->orderBy('id')
            ->get();
    }
}
