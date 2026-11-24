<?php

namespace App\Actions\Chat\Access;

use App\Models\ChatParticipant;

/**
 * Result of resolving a user's read/view access to a conversation.
 *
 * - participant: the user's active participant row, when they are one.
 * - viaSupportAdmin: true when access is granted purely by the chat-assignment role on an
 *   unassigned support chat (no participant row yet). Such a user is view-only until assigned.
 */
readonly class ConversationViewAccess
{
    public function __construct(public ?ChatParticipant $participant = null, public bool $viaSupportAdmin = false) {}
}
