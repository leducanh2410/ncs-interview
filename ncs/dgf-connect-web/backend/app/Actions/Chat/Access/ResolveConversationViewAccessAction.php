<?php

namespace App\Actions\Chat\Access;

use App\Enums\ChatConversationType;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\User;

/**
 * Resolves whether a user may VIEW a conversation (detail + message list + channel subscription):
 * either an active participant, or a chat-assignment admin on a support chat (a role check, not a
 * participant check — this is how unassigned support chats stay visible to triage admins).
 *
 * Send and mark-read keep using ResolveParticipantAccessAction (participant-only), so a support
 * admin is view-only until they assign themselves.
 */
class ResolveConversationViewAccessAction
{
    public function __construct(private readonly ResolveParticipantAccessAction $resolveParticipant) {}

    public function __invoke(User $user, ChatConversation $conversation): ?ConversationViewAccess
    {
        $participant = ($this->resolveParticipant)($user, $conversation->id);
        if ($participant !== null) {
            return new ConversationViewAccess(participant: $participant);
        }

        if (
            in_array($conversation->type, [
                ChatConversationType::Support->value,
                ChatConversationType::GuestSupport->value,
            ]) &&
            $user->hasPermission(PermissionName::ChatAssign->value)
        ) {
            return new ConversationViewAccess(viaSupportAdmin: true);
        }

        return null;
    }
}
