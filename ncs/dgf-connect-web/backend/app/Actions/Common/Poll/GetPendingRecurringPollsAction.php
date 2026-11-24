<?php

namespace App\Actions\Common\Poll;

use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Enums\RecurringCompletableType;
use App\Models\Poll;
use App\Models\User;
use App\ValueObjects\Recurring\RecurringTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetPendingRecurringPollsAction
{
    public function __construct(private readonly ResolveRecurringTriggersAction $resolveTriggers) {}

    public function __invoke(User $user): Collection
    {
        if (!$this->isEligible($user)) {
            return collect();
        }

        $polls = $this->candidates($user)->orderBy('id')->get();
        if ($polls->isEmpty()) {
            return collect();
        }

        $targets = $polls
            ->map(
                fn(Poll $poll) => new RecurringTarget(RecurringCompletableType::Poll, $poll->id, [$poll->workplace_id])
            )
            ->all();

        $triggers = ($this->resolveTriggers)($user->id, $targets);

        return $polls
            ->filter(
                fn(Poll $poll) => ($triggers[
                    RecurringTarget::keyFor(RecurringCompletableType::Poll, $poll->id, $poll->workplace_id)
                ] ??
                    null) !==
                    null
            )
            ->values();
    }

    public function hasCandidates(User $user): bool
    {
        return $this->isEligible($user) && $this->candidates($user)->exists();
    }

    private function isEligible(User $user): bool
    {
        return $user->paper_status === 'approved' && $user->staff_no !== null;
    }

    private function candidates(User $user): Builder
    {
        return Poll::query()
            ->active()
            ->where('is_recurring', true)
            ->where('type', PollType::Feedback->value)
            ->where('target_type', PollTargetType::WORKPLACE->value)
            ->whereIn(
                'workplace_id',
                DB::table('workplace_users')->where('user_id', $user->id)->select('workplace_id')
            );
    }
}
