<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\GuestSupportSession;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PusherAuthController extends Controller
{
    public function __invoke(Request $request, BroadcastManager $broadcastManager): JsonResponse
    {
        $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string', 'regex:/^private-chat\.conversation\.\d+$/'],
        ]);

        /** @var GuestSupportSession $session */
        $session = $request->attributes->get('guestSession');

        $channelName = $request->input('channel_name');
        $conversationId = (int) preg_replace('/[^0-9]/', '', $channelName);

        if ($conversationId !== $session->conversation_id) {
            return response()->json(['error' => 'E_FORBIDDEN'], 403);
        }

        /** @var PusherBroadcaster $broadcaster */
        $broadcaster = $broadcastManager->driver('pusher');
        $auth = $broadcaster->getPusher()->socket_auth($channelName, $request->input('socket_id'));

        return response()->json(json_decode($auth, true));
    }
}
