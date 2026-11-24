<?php

namespace App\Http\Controllers\Guest;

use App\Actions\ActionResult;
use App\Actions\Chat\GuestSupport\CreateGuestSupportConversationAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ErrorCode;
use App\Enums\SupportType;
use App\Http\Controllers\Controller;
use App\Models\GuestSupportSession;
use App\Services\TurnstileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class ChatSupportController extends Controller
{
    public function store(
        Request $request,
        TurnstileService $turnstile,
        CreateGuestSupportConversationAction $action
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'device_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:50'],
            'fcm_token' => ['nullable', 'string', 'max:500'],
            'turnstile_token' => ['required', 'string'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
            'support_type' => ['bail', 'nullable', 'string', Rule::enum(SupportType::class)],
        ]);

        if (!$turnstile->verify($validated['turnstile_token'], $request->ip())) {
            return (new ActionResult(error: ErrorCode::CaptchaFailed, status: 400))->toResponse();
        }

        return $action($validated)->toResponse();
    }

    public function checkActive(Request $request, TurnstileService $turnstile): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:255'],
            'turnstile_token' => ['required', 'string'],
            'fcm_token' => ['nullable', 'string', 'max:500'],
        ]);

        if (!$turnstile->verify($validated['turnstile_token'], $request->ip())) {
            return (new ActionResult(error: ErrorCode::CaptchaFailed, status: 400))->toResponse();
        }

        $session = GuestSupportSession::query()
            ->where('device_id', $validated['device_id'])
            ->with('conversation')
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return response()->json([
                'data' => [
                    'has_active_session' => false,
                    'conversation_id' => null,
                    'conversation_status' => null,
                    'guest_session_token' => null,
                ],
            ]);
        }

        if (!empty($validated['fcm_token'])) {
            $session->update(['fcm_token' => $validated['fcm_token']]);
        }

        $isActive = $session->conversation?->status === ChatConversationStatus::Open->value;

        return response()->json([
            'data' => [
                'has_active_session' => $isActive,
                'conversation_id' => $session->conversation_id,
                'conversation_status' => $session->conversation?->status,
                'guest_session_token' => Crypt::decryptString($session->session_token),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var GuestSupportSession $session */
        $session = $request->attributes->get('guestSession');
        $conversation = $session
            ->conversation()
            ->with(['participants'])
            ->first();

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'title' => $conversation->title,
                'participant_count' => $conversation->participants->count(),
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
                'guest_name' => $session->guest_name,
                'guest_email' => $session->guest_email,
            ],
        ]);
    }
}
