<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\Chat\FindDirectConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Chat\DirectConversationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function directConversation(
        DirectConversationRequest $request,
        FindDirectConversationAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, (int) $request->validated('user_id'))->toResponse();
    }
}
