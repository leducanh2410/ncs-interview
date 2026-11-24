<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Poll;
use App\Models\PollResponse;

class DeletePollAction
{
    /**
     * Delete a poll.
     */
    public function __invoke(int $id): ActionResult
    {
        $poll = Poll::query()->find($id);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $hasResponses = PollResponse::query()->where('poll_id', $id)->exists();

        if ($hasResponses) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.has_responses')]
            );
        }

        $poll->delete();

        return new ActionResult(data: true);
    }
}
