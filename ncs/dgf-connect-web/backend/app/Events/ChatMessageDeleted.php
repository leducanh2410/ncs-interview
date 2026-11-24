<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ChatMessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $id, public int $conversationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
        ];
    }
}
