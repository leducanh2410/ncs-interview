<?php

namespace App\Http\Controllers\User;

use App\Actions\Chat\Support\CreateSupportConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CreateSupportConversationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChatSupportController extends Controller
{
    public function store(
        CreateSupportConversationRequest $request,
        CreateSupportConversationAction $action
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $action($user, $request->validated())->toResponse();
    }
}
