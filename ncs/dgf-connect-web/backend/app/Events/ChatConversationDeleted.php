<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ChatConversationDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $id) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->id}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.conversation.deleted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->id];
    }
}
