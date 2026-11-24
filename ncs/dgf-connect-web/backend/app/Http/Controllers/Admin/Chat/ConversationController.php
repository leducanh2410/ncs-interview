<?php

namespace App\Http\Controllers\Admin\Chat;

use App\Actions\Chat\Admin\AddChatParticipantsAction;
use App\Actions\Chat\Admin\AssignChatParticipantRoleAction;
use App\Actions\Chat\Admin\CreateChatConversationAction;
use App\Actions\Chat\Admin\DeleteChatConversationAction;
use App\Actions\Chat\Admin\DeleteChatMessageAction;
use App\Actions\Chat\Admin\FindDirectConversationAction;
use App\Actions\Chat\Admin\ListChatParticipantsAction;
use App\Actions\Chat\Admin\RemoveChatParticipantAction;
use App\Actions\Chat\Admin\RenameChatConversationAction;
use App\Actions\Chat\Admin\UpdateEmployeePermissionsAction;
use App\Actions\Chat\Conversation\GetConversationDetailAction;
use App\Actions\Chat\Conversation\GetConversationsAction;
use App\Actions\Chat\Lifecycle\CloseConversationAction;
use App\Actions\Chat\Lifecycle\ReopenConversationAction;
use App\Actions\Chat\Read\MarkConversationReadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Chat\AddChatParticipantsRequest;
use App\Http\Requests\Admin\Chat\AssignParticipantRoleRequest;
use App\Http\Requests\Admin\Chat\DirectConversationRequest;
use App\Http\Requests\Admin\Chat\ListChatParticipantsRequest;
use App\Http\Requests\Admin\Chat\RenameConversationRequest;
use App\Http\Requests\Admin\Chat\StoreConversationRequest;
use App\Http\Requests\Admin\Chat\UpdateEmployeePermissionsRequest;
use App\Http\Requests\Chat\CloseConversationRequest;
use App\Http\Requests\Chat\ListConversationsRequest;
use App\Http\Requests\Chat\MarkReadRequest;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
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

        return $action($user, $conversation, includeGlobalAdminFlag: true, includeReadState: true)->toResponse();
    }

    public function store(StoreConversationRequest $request, CreateChatConversationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $request->validated())->toResponse();
    }

    public function findDirectConversation(
        DirectConversationRequest $request,
        FindDirectConversationAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, (int) $request->validated('user_id'))->toResponse();
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

    public function updateEmployeePermissions(
        ChatConversation $conversation,
        UpdateEmployeePermissionsRequest $request,
        UpdateEmployeePermissionsAction $action
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

    public function rename(
        ChatConversation $conversation,
        RenameConversationRequest $request,
        RenameChatConversationAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }

    public function addParticipants(
        ChatConversation $conversation,
        AddChatParticipantsRequest $request,
        AddChatParticipantsAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }

    public function removeParticipant(
        ChatConversation $conversation,
        int $userId,
        RemoveChatParticipantAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = auth()->user();

        return $action($user, $conversation, $userId)->toResponse();
    }

    public function listParticipants(
        ChatConversation $conversation,
        ListChatParticipantsRequest $request,
        ListChatParticipantsAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse(true);
    }

    public function assignParticipantRole(
        ChatConversation $conversation,
        int $userId,
        AssignParticipantRoleRequest $request,
        AssignChatParticipantRoleAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $userId, $request->validated()['role'])->toResponse();
    }

    public function destroyMessage(
        ChatConversation $conversation,
        int $messageId,
        DeleteChatMessageAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = auth()->user();

        return $action($user, $conversation, $messageId)->toResponse();
    }

    public function destroy(ChatConversation $conversation, DeleteChatConversationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return $action($user, $conversation)->toResponse();
    }
}
