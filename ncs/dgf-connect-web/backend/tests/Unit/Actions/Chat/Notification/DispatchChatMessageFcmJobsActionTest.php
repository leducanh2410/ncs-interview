<?php

namespace Tests\Unit\Actions\Chat\Notification;

use App\Actions\Chat\Notification\DispatchChatMessageFcmJobsAction;
use App\Jobs\SendChatMessageFcmJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchChatMessageFcmJobsActionTest extends TestCase
{
    public function testDispatchesUniqueRecipientsInChunksToDedicatedQueue(): void
    {
        Queue::fake();
        $recipientIds = [...range(1, 205), 205];

        app(DispatchChatMessageFcmJobsAction::class)->execute(
            $recipientIds,
            42,
            ['en' => 'Title'],
            ['en' => 'Body'],
            ['type' => 'chat_message']
        );

        Queue::assertPushedOn(SendChatMessageFcmJob::QUEUE, SendChatMessageFcmJob::class);
        Queue::assertPushed(SendChatMessageFcmJob::class, 3);

        $jobs = Queue::pushed(SendChatMessageFcmJob::class);
        $this->assertSame([100, 100, 5], $jobs->map(fn($job) => count($job->recipientUserIds))->all());
        $this->assertSame(range(1, 205), $jobs->flatMap(fn($job) => $job->recipientUserIds)->values()->all());
    }
}
