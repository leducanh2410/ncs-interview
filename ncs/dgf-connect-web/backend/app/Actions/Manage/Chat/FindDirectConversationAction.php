<?php

namespace App\Actions\Manage\Chat;

use App\Actions\ActionResult;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Models\ChatConversation;
use App\Models\User;

/**
 * Looks up the existing 1-on-1 conversation between the authenticated user and another user.
 *
 * There is no dedicated "direct" conversation type: a direct chat is an admin_group conversation
 * whose only two active participants are these two users — the same shape the existing "new
 * conversation" flow produces (POST .../chat/conversations, type=admin_group) when a single
 * employee is selected. Group/shift/user-group conversations are excluded even if they happen to
 * have exactly two active members at the moment, since those are scoped chats, not personal ones.
 */
class FindDirectConversationAction
{
    public function __invoke(User $user, int $otherUserId): ActionResult
    {
        // Guard first: with otherUserId === user->id the two whereHas clauses below become
        // identical (both match the caller's own participant row), so the "two distinct active
        // participants" check would be satisfied by any admin_group conversation the caller
        // happens to have with exactly one other active member — returning someone else's chat.
        // The HTTP layer also rejects this as 422 (invalid input); this 404 is defense in depth
        // for direct action calls.
        if ($otherUserId === $user->id) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $conversation = ChatConversation::query()
            ->where('type', ChatConversationType::AdminGroup->value)
            ->whereNull('system_key')
            ->whereHas(
                'participants',
                fn($query) => $query->where('user_id', $user->id)->where('status', ChatParticipantStatus::Active->value)
            )
            ->whereHas(
                'participants',
                fn($query) => $query
                    ->where('user_id', $otherUserId)
                    ->where('status', ChatParticipantStatus::Active->value)
            )
            ->whereHas(
                'participants',
                fn($query) => $query->where('status', ChatParticipantStatus::Active->value),
                '=',
                2
            )
            // Conversation creation has no dedup safeguard for admin_group (only shift_group does),
            // so duplicate direct pairs can exist (e.g. a double-tap on the create-after-404
            // fallback). last_activity_at is the created_at of the latest non-system message
            // (NULL when the thread has none), so NULL-last then recency then id prefers a
            // thread with real messages over an empty duplicate, and the currently-active
            // thread over an older one with more history.
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->first();

        if ($conversation === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        return new ActionResult(data: ['conversation_id' => $conversation->id]);
    }
}
