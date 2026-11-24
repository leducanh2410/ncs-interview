<?php

namespace App\Actions\Chat\Conversation;

use App\Actions\ActionResult;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantStatus;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Str;

class GetConversationsAction
{
    public function __construct(private readonly FilterChatBodyAction $filterBody) {}

    /**
     * List conversations where the user is an active participant, newest activity first.
     * Unread counts come from the denormalized participant counter (no per-row N+1).
     */
    public function __invoke(User $user, array $params): ActionResult
    {
        $pageSize = $params['page_size'] ?? 15;
        $search = isset($params['search']) ? mb_strtoupper(mb_trim($params['search'])) : null;
        $search = $search !== '' ? $search : null;

        // Default to open conversations; 'all' opts out of the status filter, so closed chats are
        // hidden unless the caller explicitly asks for them (status=closed) or for everything.
        $status = $params['status'] ?? ChatConversationStatus::Open->value;

        // Optional category split: 'chat' = group chats, 'support' = support + guest support.
        $category = $params['category'] ?? null;
        $chatTypes = [
            ChatConversationType::AdminGroup->value,
            ChatConversationType::ShiftGroup->value,
            ChatConversationType::UserGroup->value,
        ];
        $supportTypes = [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value];

        $conversations = ChatConversation::query()
            ->whereHas('participants', function ($query) use ($user) {
                $query->where('user_id', $user->id)->where('status', ChatParticipantStatus::Active->value);
            })
            ->when($status !== 'all', fn($query) => $query->where('status', $status))
            ->when($category === 'chat', fn($query) => $query->whereIn('type', $chatTypes))
            ->when($category === 'support', fn($query) => $query->whereIn('type', $supportTypes))
            ->when($search !== null, function ($query) use ($user, $search) {
                $upper = $search;
                $query->whereHas('participants', function ($q) use ($user, $upper) {
                    $q->where('user_id', '!=', $user->id)
                        ->where('status', ChatParticipantStatus::Active->value)
                        ->whereHas('user', function ($u) use ($upper) {
                            $u->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$upper}%"])
                                ->orWhereRaw('UPPER(staff_no) LIKE ?', ["%{$upper}%"])
                                ->orWhereRaw('UPPER(short_name) LIKE ?', ["%{$upper}%"]);
                        });
                });
            })
            ->with([
                'lastMessage.sender:id,first_name,last_name,profile_picture,short_name',
                'participants' => fn($query) => $query->where('user_id', $user->id),
            ])
            ->withCount([
                'participants as participant_count' => fn($query) => $query->where(
                    'status',
                    ChatParticipantStatus::Active->value
                ),
            ])
            ->orderByRaw('system_key IS NULL')
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($pageSize);

        $filter = $this->filterBody;

        $conversations->through(function (ChatConversation $conversation) use ($filter) {
            /** @var ChatParticipant|null $self */
            $self = $conversation->participants->first();
            $lastMessage = $conversation->lastMessage;

            return [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'system_key' => $conversation->system_key,
                'status' => $conversation->status,
                'title' => $conversation->title,
                'support_type' => $conversation->support_type,
                'participant_count' => $conversation->participant_count,
                'unread_count' => $self?->unread_count ?? 0,
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
                'last_message' => $lastMessage
                    ? [
                        'id' => $lastMessage->id,
                        'message_type' => $lastMessage->message_type,
                        'body' => Str::limit(
                            (string) ($lastMessage->message_type !== ChatMessageType::System->value
                                ? $filter($lastMessage->body)
                                : $lastMessage->body),
                            120
                        ),
                        'system_payload' => $lastMessage->system_payload,
                        'sender_user_id' => $lastMessage->sender_user_id,
                        'sender_name' => $lastMessage->sender?->short_name,
                        'sender_profile_picture' => $lastMessage->sender?->profile_picture,
                        'created_at' => optional($lastMessage->created_at)->toIso8601String(),
                    ]
                    : null,
            ];
        });

        return new ActionResult(data: $conversations);
    }
}
