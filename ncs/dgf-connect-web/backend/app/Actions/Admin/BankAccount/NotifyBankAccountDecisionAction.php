<?php

namespace App\Actions\Admin\BankAccount;

use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\UserBankAccount;

class NotifyBankAccountDecisionAction
{
    private const CONTENT_MAX_LENGTH = 255;

    public function __invoke(UserBankAccount $bankAccount, NotificationType $type): Notification
    {
        $langKey = $type->value;
        $note = $bankAccount->rejected_note ?? '';
        $replace = ['note' => $note];

        $notification = Notification::query()->create([
            'type' => $type->value,
            'target_type' => NotificationTargetType::All->value,
            'title' => __("lang.{$langKey}.title", $replace),
            'content' => $this->content($langKey, $note),
            'icon' => NotificationIcon::MONEY->value,
            'status' => NotificationStatus::PENDING->value,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'user_id' => $bankAccount->user_id,
            'data' => ['bank_account_id' => $bankAccount->id],
        ]);
        $notification->translations()->createMany(
            array_map(
                fn(string $lang) => [
                    'lang' => $lang,
                    'title' => __("lang.{$langKey}.title", $replace, $lang),
                    'content' => $this->content($langKey, $note, $lang),
                ],
                Locale::getTranslationLocales()
            )
        );

        return $notification;
    }

    private function content(string $langKey, string $note, ?string $lang = null): string
    {
        $content = __("lang.{$langKey}.content", ['note' => $note], $lang);
        $overflow = mb_strlen($content) - self::CONTENT_MAX_LENGTH;
        if ($overflow <= 0) {
            return $content;
        }

        $shortenedNote = mb_substr($note, 0, max(mb_strlen($note) - $overflow - 1, 0)) . '…';

        return mb_substr(__("lang.{$langKey}.content", ['note' => $shortenedNote], $lang), 0, self::CONTENT_MAX_LENGTH);
    }
}
