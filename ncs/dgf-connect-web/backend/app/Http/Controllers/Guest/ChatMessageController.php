<?php

namespace App\Http\Controllers\Guest;

use App\Actions\Chat\GuestSupport\GetGuestMessagesAction;
use App\Actions\Chat\GuestSupport\SendGuestMessageAction;
use App\Http\Controllers\Controller;
use App\Models\GuestSupportSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function index(Request $request, int $id, GetGuestMessagesAction $action): JsonResponse
    {
        /** @var GuestSupportSession $session */
        $session = $request->attributes->get('guestSession');

        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $action($session, $validated)->toResponse();
    }

    public function store(Request $request, int $id, SendGuestMessageAction $action): JsonResponse
    {
        /** @var GuestSupportSession $session */
        $session = $request->attributes->get('guestSession');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
        ]);

        return $action($session, $validated)->toResponse();
    }
}
