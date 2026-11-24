<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a conversation's open/closed lifecycle state changes (close/reopen).
 * Shares the conversation private channel with NewChatMessage so clients update status inline.
 */
class ChatConversationStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatConversation $conversation) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->conversation->id}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.conversation.state';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'status' => $this->conversation->status,
            'closed_at' => optional($this->conversation->closed_at)->toIso8601String(),
            'closed_by_user_id' => $this->conversation->closed_by_user_id,
            'closed_by_role' => $this->conversation->closed_by_role,
            'close_reason' => $this->conversation->close_reason,
            'can_employee_reopen' => $this->conversation->can_employee_reopen,
        ];
    }
}
