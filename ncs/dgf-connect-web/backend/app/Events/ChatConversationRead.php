<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A participant moved their read marker forward.
 *
 * Carries only the marker, never message content, so clients can redraw read receipts (whose
 * avatar sits under which message) without refetching the conversation. Emitted only when the
 * marker actually advances — re-reading an already-read conversation broadcasts nothing.
 */
class ChatConversationRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public ?int $lastReadMessageId,
        public ?string $lastReadAt
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->conversationId}.read")];
    }

    public function broadcastAs(): string
    {
        return 'chat.conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
            'last_read_at' => $this->lastReadAt,
        ];
    }
}
