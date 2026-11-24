<?php

namespace App\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Actions\Common\Poll\FlagEligibleUsersAction;
use App\Enums\ErrorCode;
use App\Models\Poll;

class ActivatePollAction
{
    private FlagEligibleUsersAction $flagEligibleUsersAction;

    public function __construct(FlagEligibleUsersAction $flagEligibleUsersAction)
    {
        $this->flagEligibleUsersAction = $flagEligibleUsersAction;
    }

    /**
     * Activate a poll.
     */
    public function __invoke(int $id): ActionResult
    {
        $poll = Poll::query()->find($id);

        if (!$poll) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if ($poll->is_active) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => __('poll.already_active')]
            );
        }

        $poll->update(['is_active' => true]);

        $this->flagEligibleUsersAction->__invoke($poll);

        return new ActionResult(data: $poll->fresh());
    }
}
