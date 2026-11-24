<?php

namespace Tests\Unit\Actions\Chat\Broadcast;

use App\Actions\Chat\Broadcast\DispatchConversationCreatedAction;
use App\Events\ChatConversationCreated;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DispatchConversationCreatedActionTest extends TestCase
{
    private function payload(int $participantCount = 3): array
    {
        return [
            'id' => 42,
            'type' => 'user_group',
            'title' => 'Big group',
            'participants' => array_fill(0, $participantCount, ['user_id' => 1, 'name' => 'X']),
        ];
    }

    public function testBatchesRecipientsIntoChunksToBoundBroadcastJobs(): void
    {
        config(['chat.broadcast.channel_chunk_size' => 2]);
        Event::fake([ChatConversationCreated::class]);

        (new DispatchConversationCreatedAction())([10, 11, 12, 13, 14], $this->payload());

        // 5 recipients / chunk size 2 => 3 broadcast events, not 5.
        Event::assertDispatchedTimes(ChatConversationCreated::class, 3);
    }

    public function testStripsRosterFromBroadcastPayloadButKeepsCount(): void
    {
        config(['chat.broadcast.channel_chunk_size' => 100]);
        Event::fake([ChatConversationCreated::class]);

        (new DispatchConversationCreatedAction())([10, 11], $this->payload(3));

        Event::assertDispatched(
            ChatConversationCreated::class,
            fn(ChatConversationCreated $event) => $event->userIds === [10, 11] &&
                !array_key_exists('participants', $event->conversation) &&
                $event->conversation['participants_count'] === 3 &&
                $event->conversation['id'] === 42
        );
    }

    public function testDeduplicatesRecipientsAndSkipsWhenEmpty(): void
    {
        config(['chat.broadcast.channel_chunk_size' => 100]);
        Event::fake([ChatConversationCreated::class]);

        $action = new DispatchConversationCreatedAction();
        $action([10, 10, 11], $this->payload());
        $action([], $this->payload());

        Event::assertDispatchedTimes(ChatConversationCreated::class, 1);
        Event::assertDispatched(
            ChatConversationCreated::class,
            fn(ChatConversationCreated $event) => $event->userIds === [10, 11]
        );
    }
}
