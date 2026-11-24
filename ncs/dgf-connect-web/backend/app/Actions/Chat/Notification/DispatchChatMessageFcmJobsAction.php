<?php

namespace App\Actions\Chat\Notification;

use App\Jobs\SendChatMessageFcmJob;

class DispatchChatMessageFcmJobsAction
{
    public const RECIPIENTS_PER_JOB = 100;

    /**
     * @param  array<int, int>  $recipientUserIds
     * @param  array<string, string>  $titleByLocale
     * @param  array<string, string>  $bodyByLocale
     * @param  array<string, string>  $data
     */
    public function execute(
        array $recipientUserIds,
        int $conversationId,
        array $titleByLocale,
        array $bodyByLocale,
        array $data
    ): void {
        $recipientUserIds = array_values(array_unique($recipientUserIds));

        foreach (array_chunk($recipientUserIds, self::RECIPIENTS_PER_JOB) as $recipientChunk) {
            SendChatMessageFcmJob::dispatch($recipientChunk, $conversationId, $titleByLocale, $bodyByLocale, $data);
        }
    }
}
