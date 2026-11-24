<?php

namespace App\Actions\Chat\Admin;

use App\Actions\ActionResult;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantStatus;
use App\Models\ChatConversation;
use App\Models\User;

/**
 * Locates the existing one-to-one `admin_group` conversation between the acting
 * administrator and a target employee, if one exists. One-to-one chats are not a
 * distinct conversation type: they are `admin_group` conversations whose active
 * participant set happens to be exactly two users.
 */
readonly class FindDirectConversationAction
{
    public function __invoke(User $actor, int $targetUserId): ActionResult
    {
        // With actor === target the two whereHas clauses below collapse into the same
        // condition, so "has target as an active participant" is trivially satisfied by any
        // conversation that has the actor as one — the query would then return the actor's
        // most recent unrelated 1:1 conversation instead of "no match", opening the wrong
        // chat for the caller.
        if ($targetUserId === $actor->id) {
            return new ActionResult(data: ['conversation_id' => null]);
        }

        $conversation = ChatConversation::query()
            ->where('type', ChatConversationType::AdminGroup->value)
            ->whereNull('system_key')
            // Only an open conversation can actually receive a message (SendMessageAction
            // rejects closed ones), so a closed match must fall through to the
            // create-direct flow rather than sending the admin into a dead end.
            ->where('status', ChatConversationStatus::Open->value)
            ->whereHas(
                'participants',
                fn($query) => $query
                    ->where('user_id', $actor->id)
                    ->where('status', ChatParticipantStatus::Active->value)
            )
            ->whereHas(
                'participants',
                fn($query) => $query
                    ->where('user_id', $targetUserId)
                    ->where('status', ChatParticipantStatus::Active->value)
            )
            ->whereRaw(
                '(select count(*) from chat_participants where chat_participants.conversation_id = chat_conversations.id and chat_participants.status = ?) = 2',
                [ChatParticipantStatus::Active->value]
            )
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->first();

        return new ActionResult(data: ['conversation_id' => $conversation?->id]);
    }
}
