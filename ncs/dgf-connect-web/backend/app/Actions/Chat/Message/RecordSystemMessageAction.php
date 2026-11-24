<?php

namespace App\Actions\Chat\Message;

use App\Enums\ChatMessageType;
use App\Enums\ChatSystemMessageType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

/**
 * Appends a system message to a conversation's timeline (the human-readable chat history:
 * created / title set / closed / reopened / admin assigned, …).
 *
 * Stores a translation key in `body` and structured values in `system_payload`; the client
 * renders per-locale. Updates last_message_* so the entry shows in the timeline and the
 * conversation-list preview, but deliberately does NOT touch last_activity_at (that drives the
 * M5 support inactivity timer — system events must not reset it) and does NOT bump unread_count.
 *
 * Callers run this inside their own DB transaction and dispatch NewChatMessage for the returned
 * message after commit (the existing event already broadcasts any message, including system rows).
 */
class RecordSystemMessageAction
{
    public function __invoke(
        ChatConversation $conversation,
        ChatSystemMessageType $type,
        ?User $actor = null,
        array $payload = []
    ): ChatMessage {
        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => null,
            'message_type' => ChatMessageType::System->value,
            'body' => $type->value,
            'system_payload' => array_merge(
                [
                    'actor_user_id' => $actor?->id,
                    'actor_name' => $actor?->short_name,
                ],
                $payload
            ),
        ]);

        $conversation
            ->forceFill([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
            ])
            ->save();

        return $message;
    }
}
