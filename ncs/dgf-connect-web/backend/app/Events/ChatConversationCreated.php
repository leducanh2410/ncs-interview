<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies one or more recipients that a conversation now exists in their list.
 *
 * The same payload is delivered to every recipient, so a single event fans out to many
 * private user-{id} channels in one trigger (see DispatchConversationCreatedAction, which
 * batches recipients to stay under the Pusher channels-per-trigger ceiling). The payload is
 * intentionally a lightweight summary without the participant roster — clients refetch the
 * conversation on receipt — so it stays well under the broadcaster's message-size limit.
 *
 * @param array<int> $userIds
 * @param array<string, mixed> $conversation
 */
class ChatConversationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $userIds, public array $conversation) {}

    public function broadcastOn(): array
    {
        return array_map(fn(int $userId) => new PrivateChannel("user-{$userId}"), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.conversation.created';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation,
        ];
    }
}
