<?php

namespace App\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Actions\Manage\Notification\ResolveNotificationRecipientsAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Models\Notification;
use App\Models\User;
use App\Services\User\NotificationService;
use App\ValueObjects\RecruitmentCriteria;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class PushNotificationToUser implements ShouldQueue
{
    use Queueable;

    private const SYSTEM_TOPIC_PREFIX = 'system_';

    private const WORKPLACE_TOPIC_PREFIX = 'workplace_';

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        ?ResolveFirebaseMessagingAction $resolveMessaging = null,
        ?NormalizeFirebaseMessageDataAction $normalizeData = null,
        ?CalculateUserBadgeCountAction $badgeCounter = null
    ): void {
        $notifications = Notification::query()
            ->with('translations')
            ->where('status', NotificationStatus::PENDING)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->take(500)
            ->get();

        if ($notifications->isEmpty()) {
            return;
        }

        $userIds = $notifications->pluck('user_id')->filter()->unique()->values();
        $users = collect();
        $badgeByUser = [];
        if ($userIds->isNotEmpty()) {
            $users = User::query()
                ->whereIn('id', $userIds)
                ->with([
                    'devices' => static function ($query) {
                        return $query->whereNotNull('fcm_token');
                    },
                ])
                ->get()
                ->keyBy('id');

            $iosUserIds = $users
                ->filter(
                    static fn(User $user) => $user->devices->contains(
                        static fn($device) => !empty($device->fcm_token) && $device->isIos()
                    )
                )
                ->keys()
                ->all();

            if (!empty($iosUserIds)) {
                $badgeCounter = $badgeCounter ?? app(CalculateUserBadgeCountAction::class);
                $badgeByUser = $badgeCounter->executeForUsers($iosUserIds);
            }
        }

        $resolver = $resolveMessaging ?? app(ResolveFirebaseMessagingAction::class);
        $normalizer = $normalizeData ?? app(NormalizeFirebaseMessageDataAction::class);
        $messaging = $resolver->execute();
        $adminMessaging = $resolver->execute('admin');

        foreach ($notifications as $notification) {
            // Recruitment notifications keep user_id null — so the admin list's whereNull
            // filter still finds them — but they are addressed to a resolved list rather
            // than a topic, which no topic subscription can express. They therefore branch
            // off before the broadcast path, which is left exactly as it was.
            if (!$notification->user_id && $notification->isTargeted()) {
                $this->deliverToResolvedRecipients($notification, $normalizer, $messaging, $adminMessaging);

                continue;
            }

            if (!$notification->user_id) {
                $data = array_merge($notification->data ?? [], [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'icon' => $notification->icon,
                    'url' => $notification->url,
                ]);
                $workplaceId = Arr::get($data, 'workplace_id');
                $data = $normalizer->execute($data);
                $prefix = $workplaceId ? self::WORKPLACE_TOPIC_PREFIX : self::SYSTEM_TOPIC_PREFIX;
                $messages = [
                    CloudMessage::new()
                        ->withNotification([
                            'title' => $notification->title,
                            'body' => $notification->content,
                        ])
                        ->withData($data)
                        ->toTopic($prefix . App::getLocale()),
                ];
                if ($notification->translations->isNotEmpty()) {
                    foreach ($notification->translations as $translation) {
                        $messages[] = CloudMessage::new()
                            ->withNotification([
                                'title' => $translation->title,
                                'body' => $translation->content,
                            ])
                            ->withData($data)
                            ->toTopic($prefix . $translation->lang);
                    }
                }

                // Broadcast to both Firebase projects so both employee and admin apps receive it.
                $messagingInstances = array_filter([$messaging, $adminMessaging]);

                try {
                    foreach ($messagingInstances as $instance) {
                        foreach ($messages as $message) {
                            $instance->send($message);
                        }
                    }
                    $notification->update(['status' => NotificationStatus::SENT, 'processed_at' => now()]);
                    Cache::forget(NotificationService::SYSTEM_NOTIFICATIONS_CACHE_KEY);
                } catch (Throwable $e) {
                    Log::error("Push notification to system failed: {$e->getMessage()}", [
                        'id' => $notification->id,
                    ]);
                    $notification->update(['status' => NotificationStatus::FAILED, 'processed_at' => now()]);
                }
            } else {
                $user = $users->get($notification->user_id);
                $userDevices = $user->devices;
                $data = $notification->data ?? [];
                $data['id'] = $notification->id;
                $data['type'] = $notification->type;
                $data['icon'] = $notification->icon;
                $data['user_id'] = $notification->user_id;
                $data = $normalizer->execute($data);
                $title = $notification->title;
                $content = $notification->content;
                $lang = Arr::get($user->settings ?? [], 'lang');
                if ($lang) {
                    // Unlike the recruitment path below, there's no second locale-dependent
                    // element (no bonus line) to end up mismatched here, so a fallback row's
                    // filler content is shown as-is — it's still real, readable content, and
                    // withholding it would only leave the recipient with the base language
                    // instead.
                    $translation = $notification->translations->firstWhere('lang', $lang);
                    if ($translation) {
                        $title = $translation->title;
                        $content = $translation->content;
                    }
                }

                $message = CloudMessage::new()
                    ->withNotification([
                        'title' => $title,
                        'body' => $content,
                    ])
                    ->withData($data);

                if (isset($badgeByUser[$notification->user_id])) {
                    $message = $message->withApnsConfig([
                        'payload' => ['aps' => ['badge' => $badgeByUser[$notification->user_id]]],
                    ]);
                }

                if ($userDevices->isNotEmpty()) {
                    $tokens = $userDevices->pluck('fcm_token')->toArray();

                    // Admin users register via the admin Firebase project — use matching credentials.
                    $project = $user->hasPermission(PermissionName::ManagerAuth->value) ? 'admin' : 'app';
                    $userMessaging = $project === 'admin' ? $adminMessaging : $messaging;

                    try {
                        if (!$userMessaging) {
                            $notification->update(['status' => NotificationStatus::SENT, 'processed_at' => now()]);

                            continue;
                        }

                        $sendReport = $userMessaging->sendMulticast($message, $tokens);
                        if ($sendReport->successes()->count()) {
                            Log::info('Push notification to user success', [
                                'id' => $notification->id,
                                'user_id' => $notification->user_id,
                                'count' => $sendReport->successes()->count(),
                            ]);
                            $notification->update(['status' => NotificationStatus::SENT, 'processed_at' => now()]);
                        } else {
                            if ($sendReport->hasFailures()) {
                                Log::error('Push notification to user failed', [
                                    'id' => $notification->id,
                                    'user_id' => $notification->user_id,
                                    'errors' => array_map(static function ($error) {
                                        return $error->error()->getMessage();
                                    }, $sendReport->failures()->getItems()),
                                ]);
                            }
                            $notification->update([
                                'status' => $this->undeliveredStatus($notification),
                                'processed_at' => now(),
                            ]);
                        }
                    } catch (Throwable $e) {
                        Log::error("Push notification to user failed: {$e->getMessage()}", [
                            'id' => $notification->id,
                            'user_id' => $notification->user_id,
                        ]);
                        $notification->update([
                            'status' => $this->undeliveredStatus($notification),
                            'processed_at' => now(),
                        ]);
                    }
                } else {
                    Log::error('Push notification to user: User has no device', [
                        'id' => $notification->id,
                        'user_id' => $notification->user_id,
                    ]);
                    $notification->update([
                        'status' => $this->undeliveredStatus($notification),
                        'processed_at' => now(),
                    ]);
                }
            }
        }
    }

    private function undeliveredStatus(Notification $notification): NotificationStatus
    {
        return NotificationType::tryFrom($notification->type)?->staysInAppWhenPushFails()
            ? NotificationStatus::SENT
            : NotificationStatus::FAILED;
    }

    /**
     * Resolves the recipient list, then pushes to those people individually.
     *
     * Status semantics differ deliberately from the single-user path. There, the push is the
     * only delivery channel, so a failed push means the notification did not arrive. Here,
     * committing the recipient rows is what makes the notification visible in-app, so that
     * commit is what SENT means: per-device failures are logged and the remaining chunks
     * still go out. Only a failure to resolve at all leaves it FAILED — and because the
     * employee list filters on SENT, a FAILED notification with a null resolved count never
     * leaks through the legacy-visibility escape hatch.
     */
    private function deliverToResolvedRecipients(
        Notification $notification,
        NormalizeFirebaseMessageDataAction $normalizer,
        mixed $messaging,
        mixed $adminMessaging
    ): void {
        try {
            $recipientIds = app(ResolveNotificationRecipientsAction::class)($notification);
        } catch (Throwable $e) {
            Log::error("Resolving notification recipients failed: {$e->getMessage()}", ['id' => $notification->id]);
            $notification->update(['status' => NotificationStatus::FAILED, 'processed_at' => now()]);

            return;
        }

        $notification->update(['status' => NotificationStatus::SENT, 'processed_at' => now()]);

        if (empty($recipientIds)) {
            // Resolved to nobody. Recorded as sent with a zero count, and deliberately not
            // downgraded to a broadcast — reaching no one is the correct outcome of the
            // criteria the admin chose, not a failure to fall back from.
            return;
        }

        $data = array_merge($notification->data ?? [], [
            'id' => $notification->id,
            'type' => $notification->type,
            'icon' => $notification->icon,
            'url' => $notification->url,
        ]);
        $data = $normalizer->execute($data);

        $translations = $notification->translations->keyBy('lang');
        $badgeByUser = $this->badgeCountsFor($recipientIds);

        User::query()
            ->whereIn('id', $recipientIds)
            ->with(['devices' => static fn($query) => $query->whereNotNull('fcm_token')])
            ->chunkById(200, function ($users) use (
                $notification,
                $data,
                $translations,
                $badgeByUser,
                $messaging,
                $adminMessaging
            ) {
                foreach ($users as $user) {
                    $this->pushToRecipient(
                        $notification,
                        $user,
                        $data,
                        $translations,
                        $badgeByUser,
                        $messaging,
                        $adminMessaging
                    );
                }
            });
    }

    /**
     * @param array<int, int> $recipientIds
     * @return array<int, int>
     */
    private function badgeCountsFor(array $recipientIds): array
    {
        $iosUserIds = User::query()
            ->whereIn('id', $recipientIds)
            ->whereHas('devices', static fn($query) => $query->whereNotNull('fcm_token')->ios())
            ->pluck('id')
            ->all();

        if (empty($iosUserIds)) {
            return [];
        }

        return app(CalculateUserBadgeCountAction::class)->executeForUsers($iosUserIds);
    }

    private function pushToRecipient(
        Notification $notification,
        User $user,
        array $data,
        mixed $translations,
        array $badgeByUser,
        mixed $messaging,
        mixed $adminMessaging
    ): void {
        $tokens = $user->devices->pluck('fcm_token')->filter()->values()->all();
        if (empty($tokens)) {
            // No device to push to. Still a recipient, and still sees it in-app.
            return;
        }

        $title = $notification->title;
        $content = $notification->content;
        $lang = Arr::get($user->settings ?? [], 'lang');
        // The bonus line must be generated in whatever language the content actually ends up
        // in, not the recipient's raw, possibly-unmatched preference: for a genuine
        // translation that's the recipient's own language; for a fallback row (filler copied
        // from English or the base text — still shown, since it's real, readable content) it's
        // fallback_source_lang, the language that filler was actually copied from; and with no
        // row at all, title/content stay in the base language, so it falls through to
        // appendHotBonusLine's own system-default fallback. Using the row's nominal `lang`
        // instead for a fallback row is exactly the mismatch this guards against (e.g. English
        // fallback content paired with a Nepali bonus line).
        $contentLang = null;
        if ($lang) {
            $translation = $translations->get($lang);
            if ($translation) {
                $title = $translation->title;
                $content = $translation->content;
                $contentLang = $translation->is_fallback ? $translation->fallback_source_lang : $lang;
            }
        }
        $content = $this->appendHotBonusLine($notification, $content, $contentLang);

        $message = CloudMessage::new()
            ->withNotification(['title' => $title, 'body' => $content])
            ->withData($data);

        if (isset($badgeByUser[$user->id])) {
            $message = $message->withApnsConfig(['payload' => ['aps' => ['badge' => $badgeByUser[$user->id]]]]);
        }

        $userMessaging = $user->hasPermission(PermissionName::ManagerAuth->value) ? $adminMessaging : $messaging;
        if (!$userMessaging) {
            return;
        }

        try {
            // Chunked to Firebase's per-request multicast ceiling.
            foreach (array_chunk($tokens, 500) as $tokenChunk) {
                $sendReport = $userMessaging->sendMulticast($message, $tokenChunk);
                if ($sendReport->hasFailures()) {
                    Log::error('Push recruitment notification to recipient had failed tokens', [
                        'id' => $notification->id,
                        'user_id' => $user->id,
                        'errors' => array_map(
                            static fn($error) => $error->error()->getMessage(),
                            $sendReport->failures()->getItems()
                        ),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error("Push recruitment notification to recipient failed: {$e->getMessage()}", [
                'id' => $notification->id,
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Recruitment notifications advertise the on-the-spot bonus only in the push body,
     * as a final line in the same language as the content actually being sent (falling
     * back to the system default locale when nothing matched the recipient), never in the
     * stored title/content so the admin can still edit the base text without the amount
     * baked in.
     */
    private function appendHotBonusLine(Notification $notification, string $content, ?string $lang): string
    {
        $hotBonusAmount = RecruitmentCriteria::fromNotification($notification)->hotBonusAmount;
        if (!$hotBonusAmount) {
            return $content;
        }

        $line = __(
            'notification.hot_bonus_line',
            ['amount' => number_format($hotBonusAmount)],
            $lang ?? config('app.base_locale')
        );

        return $content . "\n" . $line;
    }
}
