<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Poll;
use App\Models\PollResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetPollResponsesAction
{
    /**
     * Get paginated responses for a poll.
     */
    public function __invoke(int $pollId, int $perPage = 15, ?int $occurrence = null): ActionResult|LengthAwarePaginator
    {
        $poll = Poll::query()->find($pollId);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $responses = PollResponse::query()
            ->where('poll_id', $pollId)
            ->when($occurrence !== null, fn($query) => $query->where('occurrence', $occurrence))
            ->with(['user:id,first_name,last_name,short_name', 'workplace:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $responses->getCollection()->each(function ($response) use ($poll) {
            $response->poll = $poll;
        });

        return $responses;
    }
}
