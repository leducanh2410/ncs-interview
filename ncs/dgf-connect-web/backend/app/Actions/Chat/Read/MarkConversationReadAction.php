<?php

namespace App\Actions\Chat\Read;

use App\Actions\ActionResult;
use App\Actions\Chat\Access\ResolveParticipantAccessAction;
use App\Enums\ChatMessageType;
use App\Enums\ErrorCode;
use App\Events\ChatConversationRead;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

class MarkConversationReadAction
{
    public function __construct(private readonly ResolveParticipantAccessAction $resolveAccess) {}

    public function __invoke(User $user, ChatConversation $conversation, array $params): ActionResult
    {
        $self = ($this->resolveAccess)($user, $conversation->id);
        if ($self === null) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        // Resolve the target read marker: an explicit message (scoped to this conversation) or
        // the conversation's latest message.
        $targetId = $conversation->last_message_id;
        if (isset($params['message_id'])) {
            $message = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', $params['message_id'])
                ->first();
            if ($message === null) {
                return new ActionResult(error: ErrorCode::ChatMessageNotInConversation, status: 422);
            }
            $targetId = $message->id;
        }

        // Never move the read marker backwards.
        $previousReadId = (int) $self->last_read_message_id;
        $newReadId = max($previousReadId, (int) $targetId);

        // Reconcile the denormalized counter authoritatively (self-healing, idempotent).
        $unreadCount = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $newReadId)
            ->where('message_type', '!=', ChatMessageType::System->value)
            ->when($self->joined_at !== null, function ($query) use ($self) {
                $query->where('created_at', '>=', $self->joined_at);
            })
            ->where(function ($query) use ($user) {
                $query->whereNull('sender_user_id')->orWhere('sender_user_id', '!=', $user->id);
            })
            ->count();

        $self
            ->forceFill([
                'last_read_message_id' => $newReadId ?: null,
                'last_read_at' => now(),
                'unread_count' => $unreadCount,
            ])
            ->save();

        // Read receipts on other clients only change when the marker actually advances; a repeat
        // mark-read of an already-read conversation stays silent.
        if ($newReadId > $previousReadId) {
            ChatConversationRead::dispatch(
                $conversation->id,
                $user->id,
                $self->last_read_message_id,
                optional($self->last_read_at)->toIso8601String()
            );
        }

        return new ActionResult(
            data: [
                'conversation_id' => $conversation->id,
                'last_read_message_id' => $self->last_read_message_id,
                'last_read_at' => optional($self->last_read_at)->toIso8601String(),
                'unread_count' => $self->unread_count,
            ]
        );
    }
}
