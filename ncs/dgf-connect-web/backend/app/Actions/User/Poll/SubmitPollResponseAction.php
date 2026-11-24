<?php

namespace App\Actions\User\Poll;

use App\Actions\ActionResult;
use App\Actions\Common\Poll\EvaluatePendingVotesFlagAction;
use App\Actions\Common\Recurring\RecordRecurringCompletionAction;
use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\ErrorCode;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Enums\RecurringCompletableType;
use App\Enums\RecurringCompletionSourceType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\ValueObjects\Recurring\RecurringTarget;
use App\ValueObjects\Recurring\RecurringTrigger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SubmitPollResponseAction
{
    private EvaluatePendingVotesFlagAction $evaluatePendingVotesFlagAction;

    public function __construct(
        EvaluatePendingVotesFlagAction $evaluatePendingVotesFlagAction,
        private readonly ResolveRecurringTriggersAction $resolveRecurringTriggersAction,
        private readonly RecordRecurringCompletionAction $recordRecurringCompletionAction
    ) {
        $this->evaluatePendingVotesFlagAction = $evaluatePendingVotesFlagAction;
    }

    /**
     * Submit a poll response.
     */
    public function __invoke(User $user, int $pollId, array $answer, string $ip, ?array $deviceInfo): ActionResult
    {
        $poll = Poll::query()->find($pollId);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if (!$poll->is_active) {
            return new ActionResult(
                error: ErrorCode::PollInactive,
                status: 422,
                data: ['message' => __('poll.inactive')]
            );
        }

        if ($poll->expires_at && $poll->expires_at->isPast()) {
            return new ActionResult(
                error: ErrorCode::PollExpired,
                status: 422,
                data: ['message' => __('poll.expired')]
            );
        }

        $eligibilityError = $this->checkEligibility($user, $poll);
        if ($eligibilityError) {
            return $eligibilityError;
        }

        if (
            !$poll->is_recurring &&
            PollResponse::query()->where('user_id', $user->id)->where('poll_id', $pollId)->exists()
        ) {
            return $this->alreadyResponded();
        }

        $validationError = $this->validateAnswer($poll, $answer);
        if ($validationError) {
            return $validationError;
        }

        try {
            $response = DB::transaction(function () use ($user, $poll, $answer, $ip, $deviceInfo) {
                $poll = Poll::query()->where('id', $poll->id)->lockForUpdate()->first();

                $trigger = null;
                if ($poll->is_recurring) {
                    $trigger = $this->resolveCurrentTrigger($user, $poll);
                    if ($trigger === null) {
                        return null;
                    }
                }

                $response = PollResponse::create([
                    'user_id' => $user->id,
                    'poll_id' => $poll->id,
                    'workplace_id' => $trigger?->workplaceId,
                    'occurrence' => $trigger?->occurrence ?? 1,
                    'answer' => $answer,
                    'ip_address' => $ip,
                    'device_info' => $deviceInfo,
                ]);

                if ($trigger !== null) {
                    ($this->recordRecurringCompletionAction)(
                        $user->id,
                        RecurringCompletableType::Poll,
                        $poll->id,
                        $trigger,
                        RecurringCompletionSourceType::PollResponse,
                        $response->id
                    );
                }

                $this->incrementResultCounters($poll, $answer);

                $this->evaluatePendingVotesFlagAction->__invoke($user);

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            $response = null;
        }

        if ($response === null) {
            return $this->alreadyResponded();
        }

        return new ActionResult(
            data: [
                'submitted' => true,
                'has_pending_votes' => $user->fresh()->has_pending_votes,
            ]
        );
    }

    private function resolveCurrentTrigger(User $user, Poll $poll): ?RecurringTrigger
    {
        if ($poll->workplace_id === null) {
            return null;
        }

        $target = new RecurringTarget(RecurringCompletableType::Poll, $poll->id, [$poll->workplace_id]);

        return ($this->resolveRecurringTriggersAction)($user->id, [$target])[$target->key($poll->workplace_id)] ?? null;
    }

    private function alreadyResponded(): ActionResult
    {
        return new ActionResult(
            error: ErrorCode::UnprocessableEntity,
            status: 422,
            data: ['message' => __('poll.already_responded')]
        );
    }

    /**
     * Check user eligibility for the poll.
     */
    private function checkEligibility(User $user, Poll $poll): ?ActionResult
    {
        if ($user->staff_no === null) {
            return new ActionResult(
                error: ErrorCode::Forbidden,
                status: 403,
                data: ['message' => __('poll.not_eligible')]
            );
        }

        if ($poll->target_type === PollTargetType::SPECIFIC_USERS) {
            $isTargeted = DB::table('poll_targets')->where('poll_id', $poll->id)->where('user_id', $user->id)->exists();

            if (!$isTargeted) {
                return new ActionResult(
                    error: ErrorCode::Forbidden,
                    status: 403,
                    data: ['message' => __('poll.not_eligible')]
                );
            }
        } elseif ($poll->target_type === PollTargetType::WORKPLACE && $poll->workplace_id !== null) {
            $hasWorkplaceMembership = DB::table('workplace_users')
                ->where('user_id', $user->id)
                ->where('workplace_id', $poll->workplace_id)
                ->exists();

            if (!$hasWorkplaceMembership) {
                return new ActionResult(
                    error: ErrorCode::Forbidden,
                    status: 403,
                    data: ['message' => __('poll.not_eligible')]
                );
            }
        }

        return null;
    }

    /**
     * Validate answer based on poll type.
     */
    private function validateAnswer(Poll $poll, array $answer): ?ActionResult
    {
        if ($poll->type === PollType::Vote) {
            return $this->validateVoteAnswer($poll, $answer);
        }

        return $this->validateFeedbackAnswer($poll, $answer);
    }

    /**
     * Validate vote answer.
     */
    private function validateVoteAnswer(Poll $poll, array $answer): ?ActionResult
    {
        $options = $poll->options;

        if (!is_array($options)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.invalid_options')]
            );
        }

        $candidateIds = collect($options)->map(fn($id) => (string) $id)->toArray();
        $answerKeys = collect($answer)->keys()->map(fn($key) => (string) $key)->toArray();

        if (count($candidateIds) !== count($answerKeys) || !empty(array_diff($candidateIds, $answerKeys))) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.missing_candidates')]
            );
        }

        $validValues = [1, -1, 0, '1', '-1', '0'];
        foreach ($answer as $value) {
            if (!in_array($value, $validValues, true)) {
                return new ActionResult(
                    error: ErrorCode::UnprocessableEntity,
                    status: 422,
                    data: ['message' => __('poll.invalid_vote_value')]
                );
            }
        }

        $yesCount = collect($answer)->filter(fn($v) => $v === 1 || $v === '1')->count();
        if ($yesCount > $poll->max_choice) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.exceeds_max_choice')]
            );
        }

        return null;
    }

    /**
     * Validate feedback answer.
     */
    private function validateFeedbackAnswer(Poll $poll, array $answer): ?ActionResult
    {
        if (empty($answer)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.empty_answer')]
            );
        }

        $options = $poll->options;

        if (!is_array($options)) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.invalid_options')]
            );
        }

        $choiceIds = collect($options)->pluck('id')->toArray();

        foreach ($answer as $selectedId) {
            if (!in_array($selectedId, $choiceIds, true)) {
                return new ActionResult(
                    error: ErrorCode::UnprocessableEntity,
                    status: 422,
                    data: ['message' => __('poll.invalid_choice')]
                );
            }
        }

        $selectedCount = count($answer);
        if (count(array_unique($answer)) !== $selectedCount) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.duplicate_choices')]
            );
        }

        if (!$poll->is_multiple && $selectedCount !== 1) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.single_choice_required')]
            );
        }

        if ($poll->is_multiple && $selectedCount > $poll->max_choice) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.exceeds_max_choice')]
            );
        }

        return null;
    }

    /**
     * Increment result counters safely within transaction.
     */
    private function incrementResultCounters(Poll $poll, array $answer): void
    {
        $counters = $poll->result_counters ?? [];

        if ($poll->type === PollType::Vote) {
            foreach ($answer as $candidateId => $value) {
                $key = (string) $candidateId;

                if (!isset($counters[$key])) {
                    $counters[$key] = ['yes' => 0, 'no' => 0, 'blank' => 0];
                }

                $intValue = (int) $value;

                if ($intValue === 1) {
                    $counters[$key]['yes']++;
                } elseif ($intValue === -1) {
                    $counters[$key]['no']++;
                } else {
                    $counters[$key]['blank']++;
                }
            }
        } else {
            foreach ($answer as $choiceId) {
                $key = (string) $choiceId;

                if (!isset($counters[$key])) {
                    $counters[$key] = 0;
                }

                $counters[$key]++;
            }
        }

        $poll->result_counters = $counters;
        $poll->save();
    }
}
