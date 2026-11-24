<?php

namespace App\Actions\Chat\Read;

use App\Actions\ActionResult;
use App\Actions\Chat\Access\ResolveParticipantAccessAction;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;

/**
 * Who has seen one message, and who has not.
 *
 * Derived from chat_participants.last_read_message_id rather than a per-message read table: a
 * participant has seen the message once their marker reaches it. The trade-off is `seen_at`,
 * which is the time of their latest mark-read call — for an older message that is an upper
 * bound on when they actually saw it, not the exact moment.
 *
 * Excluded from both lists: the sender (they wrote it), the caller (they are reading it right
 * now), and anyone who joined after the message was sent — they were never a recipient of it.
 */
class GetMessageReadReceiptsAction
{
    public function __construct(private readonly ResolveParticipantAccessAction $resolveAccess) {}

    public function __invoke(User $user, ChatConversation $conversation, int $messageId): ActionResult
    {
        if (($this->resolveAccess)($user, $conversation->id) === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $message = ChatMessage::query()->where('conversation_id', $conversation->id)->where('id', $messageId)->first();

        if ($message === null) {
            return new ActionResult(error: ErrorCode::ChatMessageNotInConversation, status: 422);
        }

        $excludedUserIds = array_values(array_unique(array_filter([$message->sender_user_id, $user->id])));

        $participants = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatParticipantStatus::Active->value)
            ->whereNotIn('user_id', $excludedUserIds)
            ->where(function ($query) use ($message) {
                $query->whereNull('joined_at')->orWhere('joined_at', '<=', $message->created_at);
            })
            ->with('user:id,first_name,last_name,staff_no,profile_picture,short_name')
            ->get();

        [$seen, $unseen] = $participants->partition(
            fn(ChatParticipant $participant) => (int) $participant->last_read_message_id >= $message->id
        );

        return new ActionResult(
            data: [
                'message_id' => $message->id,
                'seen_count' => $seen->count(),
                'total_count' => $participants->count(),
                'seen' => $seen
                    ->sortByDesc('last_read_at')
                    ->map(
                        fn(ChatParticipant $participant) => $this->toPayload($participant) + [
                            'seen_at' => optional($participant->last_read_at)->toIso8601String(),
                        ]
                    )
                    ->values()
                    ->all(),
                'unseen' => $unseen
                    ->sortBy(fn(ChatParticipant $participant) => $participant->user?->short_name ?? '')
                    ->map(fn(ChatParticipant $participant) => $this->toPayload($participant))
                    ->values()
                    ->all(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(ChatParticipant $participant): array
    {
        return [
            'user_id' => (int) $participant->user_id,
            'name' => $participant->user?->short_name,
            'staff_no' => $participant->user?->staff_no,
            'profile_picture' => $participant->user?->profile_picture,
            'role' => $participant->role,
        ];
    }
}
