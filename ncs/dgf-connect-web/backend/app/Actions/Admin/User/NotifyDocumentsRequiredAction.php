<?php

namespace App\Actions\Admin\User;

use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\UserPaperStepKey;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\App;

class NotifyDocumentsRequiredAction
{
    /**
     * @param string[] $missingSteps
     */
    public function __invoke(User $user, array $missingSteps, bool $studentCardExpiryMissing): Notification
    {
        $notification = Notification::query()->create([
            'type' => NotificationType::PAPER_DOCUMENTS_REQUIRED->value,
            'title' => __('lang.paper_documents_required.title'),
            'content' => $this->content($missingSteps, $studentCardExpiryMissing, App::getLocale()),
            'icon' => NotificationIcon::WARNING->value,
            'status' => NotificationStatus::PENDING->value,
            'scheduled_at' => now(),
            'user_id' => $user->id,
            'data' => null,
        ]);

        $notification->translations()->createMany(
            array_map(
                fn(string $lang) => [
                    'lang' => $lang,
                    'title' => __('lang.paper_documents_required.title', locale: $lang),
                    'content' => $this->content($missingSteps, $studentCardExpiryMissing, $lang),
                ],
                Locale::getTranslationLocales()
            )
        );

        return $notification;
    }

    /**
     * @param string[] $missingSteps
     */
    private function content(array $missingSteps, bool $studentCardExpiryMissing, string $lang): string
    {
        $documents = array_map(
            fn(string $step) => __("lang.paper_document_names.{$step}", locale: $lang),
            array_values(array_intersect(UserPaperStepKey::values(), $missingSteps))
        );

        if ($studentCardExpiryMissing) {
            $documents[] = __('lang.paper_document_names.student_card_expiry_date', locale: $lang);
        }

        return __(
            'lang.paper_documents_required.content',
            ['documents' => implode(__('lang.paper_document_names.separator', locale: $lang), $documents)],
            $lang
        );
    }
}
