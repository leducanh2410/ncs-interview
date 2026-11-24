<?php

namespace App\Actions\Chat\Notification;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\PruneInvalidFirebaseTokensAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\Notification\CalculateUserBadgeCountAction;
use App\Enums\PermissionName;
use App\Models\User;
use App\Services\Chat\ChatPresenceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class SendChatMessageFcmBatchAction
{
    private const MESSAGES_PER_FIREBASE_BATCH = 500;

    public function __construct(
        private readonly ChatPresenceService $presence,
        private readonly ResolveFirebaseMessagingAction $resolveMessaging,
        private readonly NormalizeFirebaseMessageDataAction $normalizeData,
        private readonly CalculateUserBadgeCountAction $badgeCounter,
        private readonly PruneInvalidFirebaseTokensAction $pruneInvalidTokens
    ) {}

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
        if (empty($recipientUserIds)) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $recipientUserIds)
            ->with(['devices' => static fn($query) => $query->whereNotNull('fcm_token')])
            ->get()
            ->filter(
                fn(User $user) => !$this->presence->isViewing($user->id, $conversationId) &&
                    $user->devices->isNotEmpty()
            );

        if ($users->isEmpty()) {
            return;
        }

        $adminUserIds = User::query()
            ->whereIn('id', $users->pluck('id'))
            ->whereHasPermission(PermissionName::ManagerAuth->value)
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();
        $adminUserLookup = array_fill_keys($adminUserIds, true);
        $projectByUserId = static fn(int $userId) => isset($adminUserLookup[$userId]) ? 'admin' : 'app';
        $messagingByProject = [];
        foreach (array_unique(array_map($projectByUserId, $users->pluck('id')->all())) as $project) {
            $messagingByProject[$project] = $this->resolveMessaging->execute($project);
        }

        $users = $users->filter(static fn(User $user) => $messagingByProject[$projectByUserId($user->id)] !== null);
        if ($users->isEmpty()) {
            return;
        }

        $iosUserIds = $this->iosUserIds($users);
        $badgeByUser = empty($iosUserIds) ? [] : $this->badgeCounter->executeForUsers($iosUserIds);
        $normalizedData = $this->normalizeData->execute($data);
        $messagesByProject = ['app' => [], 'admin' => []];

        foreach ($users as $user) {
            $lang = Arr::get($user->settings ?? [], 'lang', 'en');
            $title = $titleByLocale[$lang] ?? ($titleByLocale['en'] ?? '');
            $body = $bodyByLocale[$lang] ?? ($bodyByLocale['en'] ?? '');
            $message = CloudMessage::new()
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($normalizedData);

            if (isset($badgeByUser[$user->id])) {
                $message = $message->withApnsConfig([
                    'payload' => ['aps' => ['badge' => $badgeByUser[$user->id]]],
                ]);
            }

            foreach ($user->devices as $device) {
                if (!empty($device->fcm_token)) {
                    $messagesByProject[$projectByUserId($user->id)][] = $message->toToken($device->fcm_token);
                }
            }
        }

        foreach ($messagesByProject as $project => $messages) {
            foreach (array_chunk($messages, self::MESSAGES_PER_FIREBASE_BATCH) as $messageBatch) {
                try {
                    $report = $messagingByProject[$project]->sendAll($messageBatch);
                    $prunedCount = $this->pruneInvalidTokens->execute($report);

                    if ($report->hasFailures()) {
                        Log::warning('Chat FCM batch completed with failed tokens', [
                            'conversation_id' => $conversationId,
                            'project' => $project,
                            'message_count' => count($messageBatch),
                            'success_count' => $report->successes()->count(),
                            'failure_count' => $report->failures()->count(),
                            'pruned_token_count' => $prunedCount,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::error("Chat FCM batch failed: {$e->getMessage()}", [
                        'conversation_id' => $conversationId,
                        'project' => $project,
                        'message_count' => count($messageBatch),
                    ]);
                }
            }
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, int>
     */
    private function iosUserIds(Collection $users): array
    {
        return $users
            ->filter(static fn(User $user) => $user->devices->contains(static fn($device) => $device->isIos()))
            ->pluck('id')
            ->all();
    }
}
