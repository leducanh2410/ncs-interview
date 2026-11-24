<?php

namespace App\Actions\Chat\Access;

use App\Enums\ChatParticipantStatus;
use App\Models\ChatParticipant;
use App\Models\User;

/**
 * Resolves whether a user may access a conversation, returning their active participant row.
 *
 * Reused by conversation detail, message list, send, mark-read, and the private channel
 * authorization closure in routes/channels.php.
 *
 * M2: active-participant only.
 *
 * M4 extension point: this will also grant access to support-chat-assignment admins for
 * unassigned support chats — a role check, not a participant check. That branch is added when
 * the support workflow lands.
 */
class ResolveParticipantAccessAction
{
    public function __invoke(User $user, int $conversationId): ?ChatParticipant
    {
        return ChatParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->first();
    }
}
