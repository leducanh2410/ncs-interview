<?php

namespace App\Jobs;

use App\Actions\Chat\Notification\SendChatMessageFcmBatchAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one bounded recipient chunk through the matching employee/admin mobile Firebase project.
 */
class SendChatMessageFcmJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'chat-fcm';

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * @param  array<int, int>  $recipientUserIds
     * @param  array<string, string>  $titleByLocale
     * @param  array<string, string>  $bodyByLocale
     * @param  array<string, string>  $data
     */
    public function __construct(
        public array $recipientUserIds,
        public int $conversationId,
        public array $titleByLocale,
        public array $bodyByLocale,
        public array $data
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(SendChatMessageFcmBatchAction $sendBatch): void
    {
        $sendBatch->execute(
            $this->recipientUserIds,
            $this->conversationId,
            $this->titleByLocale,
            $this->bodyByLocale,
            $this->data
        );
    }
}
