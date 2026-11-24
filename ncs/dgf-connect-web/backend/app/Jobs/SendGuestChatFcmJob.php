<?php

namespace App\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class SendGuestChatFcmJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $fcmToken,
        public int $conversationId,
        public string $title,
        public string $body,
        public array $data
    ) {}

    public function handle(ResolveFirebaseMessagingAction $resolveMessaging): void
    {
        $messaging = $resolveMessaging->execute('app');
        if ($messaging === null) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(['title' => $this->title, 'body' => $this->body])
            ->withData(app(NormalizeFirebaseMessageDataAction::class)->execute($this->data))
            ->withApnsConfig(['payload' => ['aps' => ['badge' => 0]]]);

        try {
            $messaging->sendMulticast($message, [$this->fcmToken]);
        } catch (Throwable $e) {
            Log::error("Guest FCM failed for conversation {$this->conversationId}: {$e->getMessage()}", [
                'conversation_id' => $this->conversationId,
            ]);
        }
    }
}
