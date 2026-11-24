<?php

namespace App\Actions\Chat\Message;

use App\Actions\ActionResult;
use App\Actions\Chat\Access\ResolveConversationViewAccessAction;
use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatMessageType;
use App\Enums\ErrorCode;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

class GetMessagesAction
{
    public function __construct(
        private readonly ResolveConversationViewAccessAction $resolveAccess,
        private readonly FilterChatBodyAction $filterBody
    ) {}

    /**
     * Keyset cursor over chat_messages.id (covered by the (conversation_id, id) index):
     *  - before_id: history backscroll (older messages).
     *  - after_id: forward sync of missed messages.
     *  - neither: newest page.
     * before_id/after_id are mutually exclusive (validated in the form request).
     */
    public function __invoke(User $user, ChatConversation $conversation, array $params): ActionResult
    {
        if (($this->resolveAccess)($user, $conversation) === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        $beforeId = $params['before_id'] ?? null;
        $afterId = $params['after_id'] ?? null;

        if ($beforeId !== null && $afterId !== null) {
            return new ActionResult(error: ErrorCode::ChatInvalidCursor, status: 422);
        }

        $limit = $params['limit'] ?? 30;

        $query = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with([
                'attachments.attachment',
                'sender:id,first_name,last_name,profile_picture,short_name',
                'guestSupportSession:id,guest_name',
            ]);

        if ($afterId !== null) {
            // Forward sync: ascending so the client can replay in order.
            $rows = $query
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit + 1)
                ->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
        } else {
            // History (or newest page): fetch descending, then return ascending.
            $rows = $query
                ->when($beforeId !== null, fn($q) => $q->where('id', '<', $beforeId))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->sortBy('id')->values();
        }

        $filter = $this->filterBody;
        $messages = $rows
            ->map(function (ChatMessage $message) use ($filter) {
                $payload = $message->toPayloadArray();
                if ($message->message_type !== ChatMessageType::System->value) {
                    $payload['body'] = $filter($payload['body']);
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
