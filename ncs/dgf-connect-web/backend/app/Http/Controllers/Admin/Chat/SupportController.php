<?php

namespace App\Http\Controllers\Admin\Chat;

use App\Actions\Chat\Support\AssignSupportAdminsAction;
use App\Actions\Chat\Support\GetSupportConversationsAction;
use App\Actions\Chat\Support\RemoveSupportAdminsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Chat\AssignSupportAdminsRequest;
use App\Http\Requests\Admin\Chat\ListSupportConversationsRequest;
use App\Http\Requests\Admin\Chat\RemoveSupportAdminsRequest;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    public function index(ListSupportConversationsRequest $request, GetSupportConversationsAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse(true);
    }

    public function assign(
        ChatConversation $conversation,
        AssignSupportAdminsRequest $request,
        AssignSupportAdminsAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }

    public function remove(
        ChatConversation $conversation,
        RemoveSupportAdminsRequest $request,
        RemoveSupportAdminsAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $conversation, $request->validated())->toResponse();
    }
}
