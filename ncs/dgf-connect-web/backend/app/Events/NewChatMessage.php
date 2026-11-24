<?php

namespace App\Events;

use App\Actions\Chat\Keyword\FilterChatBodyAction;
use App\Enums\ChatMessageType;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts a newly-created chat message.
 *
 * Implements ShouldDispatchAfterCommit so the broadcast only fires once the surrounding database
 * transaction commits. Participant-sync (shift/user-group) records its system messages inside the
 * caller's transaction; if that transaction rolls back (e.g. shift-plan save validation failure)
 * the message rows are gone, so the broadcast must not be emitted for a message that no longer
 * exists. When no transaction is active the event dispatches immediately.
 */
class NewChatMessage implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message) {}

    /**
     * Matches the channel registered in routes/channels.php; Pusher prefixes `private-`.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.new';
    }

    public function broadcastWith(): array
    {
        $this->message->load(['attachments.attachment', 'sender:id,first_name,last_name,profile_picture,short_name']);

        $payload = $this->message->toPayloadArray();

        if ($this->message->guest_support_session_id !== null) {
            $this->message->loadMissing('guestSupportSession');
            $payload['sender_name'] = $this->message->guestSupportSession?->guest_name;
        }

        if ($this->message->message_type !== ChatMessageType::System->value) {
            $payload['body'] = app(FilterChatBodyAction::class)($payload['body']);
        }

        return $payload;
    }
}
