<?php

namespace App\Http\Controllers\User;

use App\Actions\Chat\Conversation\GetConversationDetailAction;
use App\Actions\Chat\Conversation\GetConversationsAction;
use App\Actions\Chat\Lifecycle\CloseConversationAction;
use App\Actions\Chat\Lifecycle\ReopenConversationAction;
use App\Actions\Chat\Read\MarkConversationReadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CloseConversationRequest;
use App\Http\Requests\Chat\ListConversationsRequest;
use App\Http\Requests\Chat\MarkReadRequest;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChatConversationController extends Controller
{
    public function index(ListConversationsRequest $request, GetConversationsAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $request->validated())->toResponse(true);
    }

    public function show(ChatConversation $conversation, GetConversationDetailAction $action): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return $action($user, $conversation)->toResponse();
    }

    public function markRead(
        ChatConversation $conversation,
        MarkReadRequest $request,
        MarkConversationReadAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }

    public function close(
        ChatConversation $conversation,
        CloseConversationRequest $request,
        CloseConversationAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }

    public function reopen(ChatConversation $conversation, ReopenConversationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return $action($user, $conversation)->toResponse();
    }
}
