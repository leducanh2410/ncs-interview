<?php

namespace App\Actions\Common\CheckIn;

use App\Actions\Manage\Shift\ResolveWorkplaceShiftAdminsAction;
use App\Enums\AttendanceStatus;
use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Models\CheckIn;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class NotifyAttendanceReasonAction
{
    private const REASON_PREVIEW_LENGTH = 80;

    private const CONTENT_MAX_LENGTH = 250;

    public function __construct(private readonly ResolveWorkplaceShiftAdminsAction $resolveAdmins) {}

    public function __invoke(CheckIn $checkIn, AttendanceStatus $type): int
    {
        $checkIn->loadMissing(['shift.workplace', 'shift.workplaceShift', 'user']);
        $shift = $checkIn->shift;

        $recipientUserIds = array_values(
            array_diff(($this->resolveAdmins)([$shift->workplace_id])[$shift->workplace_id] ?? [], [$checkIn->user_id])
        );
        if (empty($recipientUserIds)) {
            return 0;
        }

        $isLate = $type === AttendanceStatus::Late;
        $langKey = $isLate ? 'late' : 'early_leave';
        $minutes = $isLate ? $checkIn->late_minutes : $checkIn->early_minutes;
        $reason = $isLate ? $checkIn->late_reason : $checkIn->early_reason;
        [$start, $end] = $shift->getTimeRange();

        $replace = [
            'name' => $checkIn->user->name,
            'workplace' => $shift->workplace->name,
            'date' => Carbon::parse($shift->date)->format('Y/m/d'),
            'shift' => "{$start->format('H:i')}-{$end->format('H:i')}",
            'minutes' => $minutes,
            'reason' => Str::limit($reason, self::REASON_PREVIEW_LENGTH),
        ];
        $buildContent = fn(string $locale) => Str::limit(
            __("lang.attendance_reason.{$langKey}.content", $replace, $locale),
            self::CONTENT_MAX_LENGTH
        );

        $baseLocale = config('app.base_locale');
        $translationRows = array_map(
            fn($lang) => [
                'lang' => $lang,
                'title' => __("lang.attendance_reason.{$langKey}.title", locale: $lang),
                'content' => $buildContent($lang),
            ],
            Locale::getBaseTranslationLocales()
        );

        $data = [
            'shift_id' => $shift->id,
            'workplace_id' => $shift->workplace_id,
            'check_in_id' => $checkIn->id,
            'employee_id' => $checkIn->user_id,
            'reason_type' => $type->value,
            'minutes' => $minutes,
            'reason' => $reason,
        ];

        foreach ($recipientUserIds as $recipientUserId) {
            $notification = Notification::query()->create([
                'type' => NotificationType::ATTENDANCE_REASON_SUBMITTED->value,
                'title' => __("lang.attendance_reason.{$langKey}.title", locale: $baseLocale),
                'content' => $buildContent($baseLocale),
                'icon' => NotificationIcon::WARNING->value,
                'scheduled_at' => now(),
                'user_id' => $recipientUserId,
                'data' => $data,
            ]);

            $notification->translations()->createMany($translationRows);
        }

        return count($recipientUserIds);
    }
}
