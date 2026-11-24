<?php

namespace App\Actions\Common\Poll;

use App\Models\User;
use Illuminate\Support\Collection;

class EvaluatePendingVotesFlagAction
{
    private GetPendingPollsAction $getPendingPollsAction;

    private GetPendingRecurringPollsAction $getPendingRecurringPollsAction;

    public function __construct(
        GetPendingPollsAction $getPendingPollsAction,
        GetPendingRecurringPollsAction $getPendingRecurringPollsAction
    ) {
        $this->getPendingPollsAction = $getPendingPollsAction;
        $this->getPendingRecurringPollsAction = $getPendingRecurringPollsAction;
    }

    /**
     * Evaluate and set has_pending_votes flag for a user.
     */
    public function __invoke(User $user): void
    {
        $pendingConfigs = $this->pendingPolls($user);
        User::where('id', $user->id)->update(['has_pending_votes' => $pendingConfigs->isNotEmpty()]);
    }

    /**
     * Get pending polls for auth response.
     *
     * Returns early when has_pending_votes = false and no recurring poll can apply.
     * When has_pending_votes = true but no pending configs found, resets flag to false.
     */
    public function getForAuthResponse(User $user): array
    {
        if (!$user->has_pending_votes && !$this->getPendingRecurringPollsAction->hasCandidates($user)) {
            return [];
        }

        $pendingConfigs = $this->pendingPolls($user);

        $this->syncFlag($user, $pendingConfigs->isNotEmpty());

        if ($pendingConfigs->isEmpty()) {
            return [];
        }

        $serializeAction = new SerializePendingPollsAction();

        return $serializeAction->__invoke($pendingConfigs);
    }

    private function syncFlag(User $user, bool $hasPending): void
    {
        if ($user->has_pending_votes === $hasPending) {
            return;
        }

        User::where('id', $user->id)->update(['has_pending_votes' => $hasPending]);
        $user->has_pending_votes = $hasPending;
        $user->syncOriginalAttribute('has_pending_votes');
    }

    private function pendingPolls(User $user): Collection
    {
        return $this->getPendingPollsAction
            ->__invoke($user)
            ->concat($this->getPendingRecurringPollsAction->__invoke($user))
            ->values();
    }
}
