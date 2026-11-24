<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Poll\SubmitPollResponseAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\Poll\SubmitPollResponseRequest;
use App\Models\Poll;
use Illuminate\Http\JsonResponse;

class PollResponseController extends Controller
{
    public function respond(
        Poll $poll,
        SubmitPollResponseRequest $request,
        SubmitPollResponseAction $action
    ): JsonResponse {
        $result = $action(
            $request->user(),
            $poll->id,
            $request->input('answer'),
            $request->ip(),
            $request->input('device_info')
        );

        if ($result->getError()) {
            return ResponseHelper::error($result->getError(), $result->getStatus(), $result->getData());
        }

        return ResponseHelper::success($result->getData());
    }
}
