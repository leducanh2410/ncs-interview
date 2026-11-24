<?php

namespace App\Actions\Chat\GuestSupport;

use App\Actions\ActionResult;
use App\Actions\Chat\Keyword\CheckMessageBlockedAction;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Actions\Chat\Notification\NotifyChatRecipientsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Events\NewChatMessage;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\GuestSupportSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SendGuestMessageAction
{
    public function __construct(
        private readonly NotifyChatRecipientsAction $notify,
        private readonly CheckMessageBlockedAction $checkBlocked,
        private readonly FilterChatBodyAction $filterBody
    ) {}

    public function __invoke(GuestSupportSession $session, array $params): ActionResult
    {
        $conversation = $session->conversation;

        if ($conversation->status === ChatConversationStatus::Closed->value) {
            return new ActionResult(error: ErrorCode::ChatConversationClosed, status: 422);
        }

        $clientMessageId = $params['client_message_id'] ?? null;

        if ($clientMessageId !== null) {
            $existing = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_message_id', $clientMessageId)
                ->first();
            if ($existing !== null) {
                $payload = $existing->toPayloadArray();
                $payload['body'] = ($this->filterBody)($payload['body']);
                $payload['sender_name'] = $session->guest_name;

                return new ActionResult(data: $payload);
            }
        }

        $hasBody = filled($params['body'] ?? null);

        if ($hasBody) {
            $blockedKeywords = $this->checkBlocked->matches($params['body']);
            if ($blockedKeywords !== []) {
                return new ActionResult(
                    error: ErrorCode::ChatMessageContainsBannedKeyword,
                    status: 422,
                    data: ['keywords' => $blockedKeywords]
                );
            }
        }

        try {
            $message = DB::transaction(function () use ($session, $conversation, $params, $clientMessageId) {
                $message = ChatMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_user_id' => null,
                    'guest_support_session_id' => $session->id,
                    'client_message_id' => $clientMessageId,
                    'message_type' => ChatMessageType::Text->value,
                    'body' => $params['body'],
                ]);

                $conversation
                    ->forceFill([
                        'last_message_id' => $message->id,
                        'last_message_at' => $message->created_at,
                        'last_activity_at' => $message->created_at,
                    ])
                    ->save();

                ChatParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('status', ChatParticipantStatus::Active->value)
                    ->increment('unread_count');

                return $message;
            });
        } catch (QueryException $e) {
            if ($clientMessageId !== null) {
                $existing = ChatMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('client_message_id', $clientMessageId)
                    ->first();
                if ($existing !== null) {
                    $payload = $existing->toPayloadArray();
                    $payload['body'] = ($this->filterBody)($payload['body']);
                    $payload['sender_name'] = $session->guest_name;

                    return new ActionResult(data: $payload);
                }
            }
            throw $e;
        }

        NewChatMessage::dispatch($message);
        $this->notify->forGuestMessage($conversation, $message);

        $payload = $message->toPayloadArray();
        $payload['body'] = ($this->filterBody)($payload['body']);
        $payload['sender_name'] = $session->guest_name;

        return new ActionResult(data: $payload, status: 201);
    }
}
