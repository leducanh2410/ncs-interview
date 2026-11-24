<?php

namespace App\Actions\Manage\Notification;

use App\Models\Notification;
use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\DB;

class PersistNotificationRecipientsAction
{
    private const WRITE_CHUNK_SIZE = 1000;

    /**
     * @param array<int, int> $recipientIds
     */
    public function __invoke(Notification $notification, array $recipientIds): void
    {
        DB::transaction(function () use ($notification, $recipientIds) {
            NotificationRecipient::query()->where('notification_id', $notification->id)->delete();

            foreach (array_chunk($recipientIds, self::WRITE_CHUNK_SIZE) as $chunk) {
                NotificationRecipient::query()->insert(
                    array_map(
                        fn(int $userId) => [
                            'notification_id' => $notification->id,
                            'user_id' => $userId,
                        ],
                        $chunk
                    )
                );
            }

            $notification->forceFill(['recipients_resolved_count' => count($recipientIds)])->saveQuietly();
        });
    }
}
