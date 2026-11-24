<?php

namespace App\Http\Controllers\Guest;

use App\Actions\Chat\GuestSupport\GuestReopenConversationAction;
use App\Http\Controllers\Controller;
use App\Models\GuestSupportSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatLifecycleController extends Controller
{
    public function reopen(Request $request, int $id, GuestReopenConversationAction $action): JsonResponse
    {
        /** @var GuestSupportSession $session */
        $session = $request->attributes->get('guestSession');

        return $action($session, $session->conversation)->toResponse();
    }
}
