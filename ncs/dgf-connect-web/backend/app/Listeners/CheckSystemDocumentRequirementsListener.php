<?php

namespace App\Listeners;

use App\Actions\Common\Firebase\NormalizeFirebaseMessageDataAction;
use App\Actions\Common\Firebase\ResolveFirebaseMessagingAction;
use App\Actions\Common\User\ResolveUserLocaleAction;
use App\Events\UserPaperApproved;
use App\Models\DocumentRequiredConfig;
use App\Models\User;
use App\Services\DocumentReadConfirmationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class CheckSystemDocumentRequirementsListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly DocumentReadConfirmationService $confirmationService) {}

    public function handle(UserPaperApproved $event, ?ResolveFirebaseMessagingAction $resolveMessaging = null): void
    {
        $user = $event->user;

        $pendingConfigs = DocumentRequiredConfig::query()
            ->with('document.attachments')
            ->active()
            ->system()
            ->whereDoesntHave('documentReadConfirmations', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        if ($pendingConfigs->isEmpty()) {
            return;
        }

        $this->confirmationService->setPendingFlag($user, true);

        $this->sendNotification($user, $pendingConfigs, $resolveMessaging);
    }

    private function sendNotification(
        User $user,
        $pendingConfigs,
        ?ResolveFirebaseMessagingAction $resolveMessaging
    ): void {
        $userDevices = $user->devices()->whereNotNull('fcm_token')->get();

        if ($userDevices->isEmpty()) {
            Log::warning('User has no FCM devices for document read notification', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $data = [
            'type' => 'document_read_required',
            'pending_documents' => json_encode(
                $this->confirmationService->serializePendingConfigs($pendingConfigs),
                JSON_THROW_ON_ERROR
            ),
        ];

        // Queued listeners run at the app default locale, so resolve the recipient's own
        // language explicitly instead of relying on App::getLocale().
        $locale = app(ResolveUserLocaleAction::class)->execute($user);
        $message = CloudMessage::new()
            ->withNotification([
                'title' => __('lang.document_read_required.title', [], $locale),
                'body' => __('lang.document_read_required.content', [], $locale),
            ])
            ->withData(app(NormalizeFirebaseMessageDataAction::class)->execute($data));

        $tokens = $userDevices->pluck('fcm_token')->toArray();
        $messaging = ($resolveMessaging ?? app(ResolveFirebaseMessagingAction::class))->execute();

        if (!$messaging) {
            return;
        }

        try {
            $messaging->sendMulticast($message, $tokens);
            Log::info('Sent document read required notification', [
                'user_id' => $user->id,
                'device_count' => count($tokens),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to send document read required notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
