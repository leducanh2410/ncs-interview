<?php

namespace App\Actions\Chat\GuestSupport;

use App\Actions\ActionResult;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatMessageType;
use App\Models\ChatMessage;
use App\Models\GuestSupportSession;

class GetGuestMessagesAction
{
    public function __construct(private readonly FilterChatBodyAction $filterBody) {}

    public function __invoke(GuestSupportSession $session, array $params): ActionResult
    {
        $beforeId = $params['before_id'] ?? null;
        $limit = $params['limit'] ?? 30;

        $query = ChatMessage::query()
            ->where('conversation_id', $session->conversation_id)
            ->with(['attachments.attachment', 'sender:id,first_name,last_name,profile_picture,short_name']);

        $rows = $query
            ->when($beforeId !== null, fn($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->sortBy('id')->values();

        $filter = $this->filterBody;
        $messages = $rows
            ->map(function (ChatMessage $message) use ($session, $filter) {
                $payload = $message->toPayloadArray();
                if ($message->message_type !== ChatMessageType::System->value) {
                    $payload['body'] = $filter($payload['body']);
                }
                if ($message->guest_support_session_id !== null) {
                    $payload['sender_name'] = $session->guest_name;
                }

                return $payload;
            })
            ->all();

        return new ActionResult(
            data: [
                'messages' => $messages,
                'has_more' => $hasMore,
                'oldest_id' => $rows->first()?->id,
                'newest_id' => $rows->last()?->id,
            ]
        );
    }
}
