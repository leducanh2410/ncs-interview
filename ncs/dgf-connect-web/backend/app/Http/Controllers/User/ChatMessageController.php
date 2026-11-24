<?php

namespace App\Http\Controllers\User;

use App\Actions\Chat\Message\GetMessagesAction;
use App\Actions\Chat\Message\SendMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ListMessagesRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChatMessageController extends Controller
{
    public function index(
        ChatConversation $conversation,
        ListMessagesRequest $request,
        GetMessagesAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }

    public function store(
        ChatConversation $conversation,
        SendMessageRequest $request,
        SendMessageAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }
}
