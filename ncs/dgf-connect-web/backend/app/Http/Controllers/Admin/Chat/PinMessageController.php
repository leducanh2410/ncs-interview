<?php

namespace App\Http\Controllers\Admin\Chat;

use App\Actions\Chat\Admin\PinChatMessageAction;
use App\Actions\Chat\Admin\UnpinChatMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Chat\PinMessageRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PinMessageController extends Controller
{
    public function pin(
        ChatConversation $conversation,
        PinMessageRequest $request,
        PinChatMessageAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $message = ChatMessage::query()->findOrFail($request->validated('message_id'));

        return $action($user, $conversation, $message)->toResponse();
    }

    public function unpin(ChatConversation $conversation, UnpinChatMessageAction $action): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return $action($user, $conversation)->toResponse();
    }
}
