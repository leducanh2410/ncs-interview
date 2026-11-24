<?php

namespace App\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class SendPollAvailableFcmJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $userIds,
        public array $titleByLocale,
        public array $contentByLocale,
        public array $data
    ) {}

    public function handle(
        ?ResolveFirebaseMessagingAction $resolveMessaging = null,
        ?NormalizeFirebaseMessageDataAction $normalizeData = null,
        ?CalculateUserBadgeCountAction $badgeCounter = null
    ): void {
        $users = User::query()
            ->whereIn('id', $this->userIds)
            ->with(['devices' => static fn($query) => $query->whereNotNull('fcm_token')])
            ->get()
            ->filter(
                static fn(User $user) => $user->devices->contains(static fn($device) => !empty($device->fcm_token))
            );

        if ($users->isEmpty()) {
            return;
        }

        $iosUserIds = $users
            ->filter(
                static fn(User $user) => $user->devices->contains(
                    static fn($device) => !empty($device->fcm_token) && $device->isIos()
                )
            )
            ->pluck('id')
            ->all();

        $badgeByUser = [];
        if (!empty($iosUserIds)) {
            $badgeCounter = $badgeCounter ?? app(CalculateUserBadgeCountAction::class);
            $badgeByUser = $badgeCounter->executeForUsers($iosUserIds);
        }

        $messaging = ($resolveMessaging ?? app(ResolveFirebaseMessagingAction::class))->execute();
        $data = ($normalizeData ?? app(NormalizeFirebaseMessageDataAction::class))->execute($this->data);

        foreach ($users as $user) {
            $tokens = $user->devices->pluck('fcm_token')->toArray();

            $lang = Arr::get($user->settings ?? [], 'lang', 'en');
            $title = $this->titleByLocale[$lang] ?? ($this->titleByLocale['en'] ?? '');
            $content = $this->contentByLocale[$lang] ?? ($this->contentByLocale['en'] ?? '');

            $message = CloudMessage::new()
                ->withNotification(['title' => $title, 'body' => $content])
                ->withData($data);

            if (isset($badgeByUser[$user->id])) {
                $message = $message->withApnsConfig([
                    'payload' => ['aps' => ['badge' => $badgeByUser[$user->id]]],
                ]);
            }

            try {
                if ($messaging) {
                    $messaging->sendMulticast($message, $tokens);
                    Log::info('Poll available FCM sent to user', [
                        'user_id' => $user->id,
                        'token_count' => count($tokens),
                    ]);
                }
            } catch (Throwable $e) {
                Log::error("Poll available FCM failed for user {$user->id}: {$e->getMessage()}");
            }
        }
    }
}
