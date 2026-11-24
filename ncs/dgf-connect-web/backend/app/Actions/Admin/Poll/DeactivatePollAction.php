<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Poll;

class DeactivatePollAction
{
    /**
     * Deactivate a poll.
     */
    public function __invoke(int $id): ActionResult
    {
        $poll = Poll::query()->find($id);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if (!$poll->is_active) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.already_inactive')]
            );
        }

        $poll->update(['is_active' => false]);

        return new ActionResult(data: $poll->fresh());
    }
}
