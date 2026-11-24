<?php

namespace App\Actions\Chat\Support;

use App\Actions\ActionResult;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Str;

/**
 * Lists support conversations for chat-assignment admins. By default only unassigned ones
 * (no active admin participant) — the triage queue. `status=assigned|all` widens the view.
 * Authorization (chat.assign) is enforced by route middleware.
 */
class GetSupportConversationsAction
{
    public function __construct(private readonly FilterChatBodyAction $filterBody) {}

    public function __invoke(array $params): ActionResult
    {
        $pageSize = $params['page_size'] ?? 15;
        $filter = $params['status'] ?? 'unassigned';
        // Open/closed filter (separate from the assignment filter above). Absent or 'all' => no filter.
        $conversationStatus = $params['conversation_status'] ?? null;
        $search = isset($params['search']) ? mb_strtoupper(mb_trim($params['search'])) : null;
        $search = $search !== '' ? $search : null;
        $supportType = $params['support_type'] ?? null;

        $hasAdmin = fn($query) => $query
            ->where('role', ChatParticipantRole::Admin->value)
            ->where('status', ChatParticipantStatus::Active->value);

        $conversations = ChatConversation::query()
            ->whereIn('type', [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value])
            ->when($filter === 'unassigned', fn($query) => $query->whereDoesntHave('participants', $hasAdmin))
            ->when($filter === 'assigned', fn($query) => $query->whereHas('participants', $hasAdmin))
            ->when(
                $conversationStatus !== null && $conversationStatus !== 'all',
                fn($query) => $query->where('status', $conversationStatus)
            )
            ->when($supportType !== null, fn($query) => $query->where('support_type', $supportType))
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('createdBy', function ($u) use ($search) {
                        $u->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"])
                            ->orWhereRaw('UPPER(staff_no) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('UPPER(short_name) LIKE ?', ["%{$search}%"]);
                    })->orWhereHas('guestSupportSession', function ($g) use ($search) {
                        $g->whereRaw('UPPER(guest_name) LIKE ?', ["%{$search}%"]);
                    });
                });
            })
            ->with([
                'lastMessage.sender:id,first_name,last_name,profile_picture,short_name',
                'createdBy:id,first_name,last_name,profile_picture,short_name',
                'guestSupportSession',
            ])
            ->withCount([
                'participants as participant_count' => fn($query) => $query->where(
                    'status',
                    ChatParticipantStatus::Active->value
                ),
            ])
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($pageSize);

        $filterBody = $this->filterBody;

        $conversations->through(function (ChatConversation $conversation) use ($filterBody) {
            /** @var ChatMessage|null $lastMessage */
            $lastMessage = $conversation->lastMessage;

            return [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'support_type' => $conversation->support_type,
                'status' => $conversation->status,
                'title' => $conversation->title,
                'created_by_user_id' => $conversation->created_by_user_id,
                'created_by_name' => $conversation->createdBy?->short_name,
                'created_by_avatar_url' => $conversation->createdBy?->profile_picture,
                'is_guest' => $conversation->type === ChatConversationType::GuestSupport->value,
                'guest_name' => $conversation->guestSupportSession?->guest_name,
                'guest_email' => $conversation->guestSupportSession?->guest_email,
                'participant_count' => $conversation->participant_count,
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
                'last_message' => $lastMessage
                    ? [
                        'id' => $lastMessage->id,
                        'message_type' => $lastMessage->message_type,
                        'body' => Str::limit(
                            (string) ($lastMessage->message_type !== ChatMessageType::System->value
                                ? $filterBody($lastMessage->body)
                                : $lastMessage->body),
                            120
                        ),
                        'system_payload' => $lastMessage->system_payload,
                        'sender_user_id' => $lastMessage->sender_user_id,
                        'sender_name' =>
                            $lastMessage->sender?->short_name ??
                            ($lastMessage->sender_user_id === null && $lastMessage->message_type !== 'system'
                                ? $conversation->guestSupportSession?->guest_name
                                : null),
                        'sender_profile_picture' => $lastMessage->sender?->profile_picture,
                        'created_at' => optional($lastMessage->created_at)->toIso8601String(),
                    ]
                    : null,
            ];
        });

        return new ActionResult(data: $conversations);
    }
}
