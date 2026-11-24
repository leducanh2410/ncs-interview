<?php

namespace App\Actions\Manage\Shift;

use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Creates one aggregated administrator notification per recipient for a given
 * shift and alert kind. Both ShiftAdminAlert alert kinds share this shape:
 * an aggregate count and the workplace/time, never individual employee names.
 */
class CreateShiftAdminAlertAction
{
    private const LANG_KEYS = [
        NotificationType::SHIFT_UNCONFIRMED_ALERT->value => 'unconfirmed',
        NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value => 'missing_check_in',
    ];

    /**
     * @param int[] $recipientUserIds
     * @param int[] $affectedUserIds
     */
    public function __invoke(
        Shift $shift,
        NotificationType $type,
        array $recipientUserIds,
        array $affectedUserIds,
        Carbon $startAt
    ): int {
        $shift->loadMissing('workplace');
        $langKey = self::LANG_KEYS[$type->value];
        $affectedCount = count($affectedUserIds);
        $data = [
            'shift_id' => $shift->id,
            'workplace_id' => $shift->workplace_id,
            'user_ids' => $affectedUserIds,
        ];

        // The message is identical for every recipient (an aggregate count, never names), so
        // the title/content and their per-locale translations are built once per shift/alert
        // kind rather than recomputed inside the recipient loop.
        $title = __("lang.alert.{$langKey}.title");
        $content = $this->content($langKey, $shift, $startAt, $affectedCount);
        $translationRows = array_map(
            fn($lang) => [
                'lang' => $lang,
                'title' => __("lang.alert.{$langKey}.title", locale: $lang),
                'content' => $this->content($langKey, $shift, $startAt, $affectedCount, $lang),
            ],
            Locale::getTranslationLocales()
        );

        $created = 0;
        foreach ($recipientUserIds as $recipientUserId) {
            $notification = Notification::query()->create([
                'type' => $type->value,
                'title' => $title,
                'content' => $content,
                'icon' => NotificationIcon::WARNING->value,
                'scheduled_at' => now(),
                'user_id' => $recipientUserId,
                'data' => $data,
            ]);

            $notification->translations()->createMany($translationRows);

            $created++;
        }

        return $created;
    }

    private function content(string $langKey, Shift $shift, Carbon $startAt, int $count, ?string $locale = null): string
    {
        return __(
            "lang.alert.{$langKey}.content",
            replace: [
                'workplace' => $shift->workplace->name,
                'time' => $startAt->format('H:i'),
                'count' => $count,
            ],
            locale: $locale
        );
    }
}
