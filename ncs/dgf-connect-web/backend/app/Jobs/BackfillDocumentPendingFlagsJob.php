<?php

namespace App\Jobs;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\User\ResolveUserLocaleAction;
use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use App\Models\ShiftUser;
use App\Models\User;
use App\Services\DocumentReadConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class BackfillDocumentPendingFlagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $configId) {}

    public function handle(
        ?DocumentReadConfirmationService $confirmationService = null,
        ?ResolveFirebaseMessagingAction $resolveMessaging = null,
        ?NormalizeFirebaseMessageDataAction $normalizeData = null
    ): void {
        $confirmationService ??= app(DocumentReadConfirmationService::class);
        $config = DocumentRequiredConfig::query()->with('document.attachments')->find($this->configId);

        if (!$config) {
            Log::error('Backfill job: Config not found', ['config_id' => $this->configId]);

            return;
        }

        if (!$config->is_active) {
            Log::warning('Backfill job: Config is inactive', ['config_id' => $this->configId]);

            return;
        }

        $confirmedUserIds = DocumentReadConfirmation::query()
            ->where('document_required_config_id', $config->id)
            ->pluck('user_id')
            ->toArray();

        $pendingDocumentData = [
            'type' => 'document_read_required',
            'pending_documents' => json_encode(
                $confirmationService->serializePendingConfigs(collect([$config])),
                JSON_THROW_ON_ERROR
            ),
        ];
        $pendingDocumentData = ($normalizeData ?? app(NormalizeFirebaseMessageDataAction::class))->execute(
            $pendingDocumentData
        );

        $messaging = ($resolveMessaging ?? app(ResolveFirebaseMessagingAction::class))->execute();

        if ($config->workplace_id === null) {
            $this->processSystemBackfill($config, $confirmedUserIds, $pendingDocumentData, $messaging);
        } else {
            $this->processWorkplaceBackfill($config, $confirmedUserIds, $pendingDocumentData, $messaging);
        }
    }

    private function processSystemBackfill(
        DocumentRequiredConfig $config,
        array $confirmedUserIds,
        array $pendingDocumentData,
        ?Messaging $messaging
    ): void {
        User::query()
            ->where('paper_status', 'approved')
            ->whereNotIn('id', $confirmedUserIds)
            ->chunkById(100, function ($users) use ($config, $pendingDocumentData, $messaging) {
                $userIds = $users->pluck('id')->toArray();

                User::whereIn('id', $userIds)->update(['has_pending_documents' => true]);

                $this->sendNotifications($users, $pendingDocumentData, $messaging);

                Log::info('Backfill job: Processed system config chunk', [
                    'config_id' => $config->id,
                    'user_count' => count($userIds),
                ]);
            });
    }

    private function processWorkplaceBackfill(
        DocumentRequiredConfig $config,
        array $confirmedUserIds,
        array $pendingDocumentData,
        ?Messaging $messaging
    ): void {
        $workplaceUserIds = ShiftUser::query()
            ->whereNotNull('approved_at')
            ->whereHas('shift', function ($query) use ($config) {
                $query->where('workplace_id', $config->workplace_id);
            })
            ->whereNotIn('user_id', $confirmedUserIds)
            ->pluck('user_id')
            ->unique();

        User::query()
            ->whereIn('id', $workplaceUserIds)
            ->where('paper_status', 'approved')
            ->chunkById(100, function ($users) use ($config, $pendingDocumentData, $messaging) {
                $userIds = $users->pluck('id')->toArray();

                User::whereIn('id', $userIds)->update(['has_pending_documents' => true]);

                $this->sendNotifications($users, $pendingDocumentData, $messaging);

                Log::info('Backfill job: Processed workplace config chunk', [
                    'config_id' => $config->id,
                    'user_count' => count($userIds),
                ]);
            });
    }

    private function sendNotifications($users, array $pendingDocumentData, ?Messaging $messaging): void
    {
        // Queued jobs run at the app default locale, so build one message per recipient
        // language instead of a single shared message.
        $resolveLocale = app(ResolveUserLocaleAction::class);
        $messagesByLocale = [];

        foreach ($users as $user) {
            $locale = $resolveLocale->execute($user);
            $message = $messagesByLocale[$locale] ??= CloudMessage::new()
                ->withNotification([
                    'title' => __('lang.document_read_required.title', [], $locale),
                    'body' => __('lang.document_read_required.content', [], $locale),
                ])
                ->withData($pendingDocumentData);
            $userDevices = $user->devices()->whereNotNull('fcm_token')->get();

            if ($userDevices->isEmpty()) {
                continue;
            }

            $tokens = $userDevices->pluck('fcm_token')->toArray();

            if (!$messaging) {
                continue;
            }

            try {
                $messaging->sendMulticast($message, $tokens);
            } catch (Throwable $e) {
                Log::error('Backfill job: Failed to send notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
