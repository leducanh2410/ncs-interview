<?php

namespace App\Http\Controllers\Chat;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\HeartbeatRequest;
use App\Models\User;
use App\Services\Chat\ChatPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records the conversation a user is actively viewing so chat push can be suppressed while they are
 * looking at it. Shared by employee and admin clients — it only stores user -> conversation, message
 * access itself stays gated by the conversation/attachment routes.
 */
class ChatPresenceController extends Controller
{
    public function __construct(private readonly ChatPresenceService $presence) {}

    public function active(HeartbeatRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->presence->markActive($user->id, (int) $request->validated('conversation_id'));

        return ResponseHelper::success();
    }

    public function leave(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->presence->clearActive($user->id);

        return ResponseHelper::success();
    }
}
