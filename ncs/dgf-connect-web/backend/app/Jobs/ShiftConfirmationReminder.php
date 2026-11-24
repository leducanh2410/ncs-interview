<?php

namespace App\Jobs;

use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Models\Notification;
use App\Models\ShiftUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftConfirmationReminder implements ShouldQueue
{
    use Queueable;

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
    public function handle(): void
    {
        DB::transaction(function () {
            $date = now()->addDays(config('work.reminder.confirmation.before_day'));
            $records = ShiftUser::query()
                ->select(['shift_users.id', 'shift_users.user_id', 'shift_users.shift_id', 'shifts.date'])
                ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
                ->where('shift_users.status', ShiftUserStatus::Approved)
                ->where('shifts.date', $date)
                ->whereNull('shift_users.confirmed_at')
                ->whereNull('shift_users.confirmation_reminded_at')
                ->get();

            foreach ($records as $shiftUser) {
                $shiftDate = Carbon::parse($shiftUser->shift->date);
                $notification = Notification::query()->create([
                    'type' => NotificationType::CONFIRM_REMINDER->value,
                    'title' => __('lang.reminder.confirm.title'),
                    'content' => __(
                        'lang.reminder.confirm.content',
                        replace: ['date' => $shiftDate->format(__('lang.date_format'))]
                    ),
                    'icon' => NotificationIcon::FILE->value,
                    'scheduled_at' => now(),
                    'user_id' => $shiftUser->user_id,
                    'data' => ['shift_user_id' => $shiftUser->id],
                ]);
                $notification->translations()->createMany(
                    array_map(
                        fn($lang) => [
                            'lang' => $lang,
                            'title' => __('lang.reminder.confirm.title', locale: $lang),
                            'content' => __(
                                'lang.reminder.confirm.content',
                                replace: ['date' => $shiftDate->format(__('lang.date_format', locale: $lang))],
                                locale: $lang
                            ),
                        ],
                        Locale::getTranslationLocales()
                    )
                );

                $shiftUser->update(['confirmation_reminded_at' => now()]);
            }
        });
    }
}
