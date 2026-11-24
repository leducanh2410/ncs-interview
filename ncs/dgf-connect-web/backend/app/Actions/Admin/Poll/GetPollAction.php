<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;

class GetPollAction
{
    /**
     * Get poll detail including result_counters.
     */
    public function __invoke(int $id, ?int $occurrence = null): ActionResult
    {
        $poll = Poll::query()
            ->with(['workplace', 'creator', 'targetUsers:id,first_name,last_name,short_name,staff_no'])
            ->withCount('responses')
            ->find($id);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $poll->setRelation('targetUsers', $poll->targetUsers->map(fn(User $user) => $user->toArray()));

        $poll->available_occurrences = PollResponse::query()
            ->where('poll_id', $poll->id)
            ->distinct()
            ->orderBy('occurrence')
            ->pluck('occurrence')
            ->map(fn($value) => (int) $value)
            ->values()
            ->all();

        if ($occurrence !== null) {
            $poll->result_counters = $this->countersForOccurrence($poll, $occurrence);
        }

        if ($poll->type === PollType::Vote) {
            $candidateIds = $poll->options;
            $candidates = User::query()
                ->whereIn('id', $candidateIds)
                ->get(['id', 'first_name', 'last_name', 'short_name', 'profile_picture'])
                ->keyBy('id');

            $poll->candidate_users = collect($candidateIds)
                ->map(
                    fn($id) => [
                        'id' => $id,
                        'name' => $candidates->get($id)?->short_name ?? '',
                        'profile_picture' => $candidates->get($id)?->profile_picture ?? '',
                    ]
                )
                ->values()
                ->toArray();
        }

        return new ActionResult(data: $poll);
    }

    private function countersForOccurrence(Poll $poll, int $occurrence): array
    {
        $counters = [];
        foreach ($poll->result_counters as $key => $value) {
            $counters[$key] = is_array($value) ? ['yes' => 0, 'no' => 0, 'blank' => 0] : 0;
        }

        $answers = PollResponse::query()
            ->where('poll_id', $poll->id)
            ->where('occurrence', $occurrence)
            ->pluck('answer');

        foreach ($answers as $answer) {
            if ($poll->type === PollType::Vote) {
                foreach ($answer as $candidateId => $value) {
                    $key = (string) $candidateId;
                    $counters[$key] ??= ['yes' => 0, 'no' => 0, 'blank' => 0];
                    $bucket = match ((int) $value) {
                        1 => 'yes',
                        -1 => 'no',
                        default => 'blank',
                    };
                    $counters[$key][$bucket]++;
                }

                continue;
            }

            foreach ($answer as $choiceId) {
                $key = (string) $choiceId;
                $counters[$key] = ($counters[$key] ?? 0) + 1;
            }
        }

        return $counters;
    }
}
