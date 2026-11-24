<?php

namespace App\Jobs;

use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\Notification;
use App\Models\ShiftUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftReminder implements ShouldQueue
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
            $records = ShiftUser::query()
                ->select([
                    'shift_users.id',
                    'shift_users.user_id',
                    'shift_users.shift_id',
                    'shift_users.status',
                    'shift_users.confirmed_at',
                    'shift_users.shift_reminded_at',
                    'shift_users.check_in_reminded_at',
                    'shift_users.check_out_reminded_at',
                    'shift_users.final_confirmation_reminded_at',
                    'shifts.date',
                    'workplace_shifts.start_time',
                    'workplace_shifts.end_time',
                    'workplace_shifts.start_break_time',
                    'workplace_shifts.end_break_time',
                    'check_ins.id as check_in_id',
                    'check_ins.check_in_time',
                    'check_ins.check_out_time',
                ])
                ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
                ->join('workplace_shifts', 'shifts.workplace_shift_id', '=', 'workplace_shifts.id')
                ->leftJoin('check_ins', 'shift_users.id', '=', 'check_ins.shift_user_id')
                ->where('shift_users.status', ShiftUserStatus::Approved)
                ->whereBetween('shifts.date', [now()->startOfDay(), now()->addDay()->endOfDay()])
                ->get()
                ->groupBy('shift_id');

            foreach ($records as $shiftId => $shiftUsers) {
                [$startTime, $endTime] = WorkHelper::getShiftTimes(
                    $shiftUsers[0]->date,
                    $shiftUsers[0]->start_time,
                    $shiftUsers[0]->end_time,
                    $shiftUsers[0]->start_break_time,
                    $shiftUsers[0]->end_break_time
                );

                $minutesUntilEnd = (int) now()->diffInMinutes($endTime);
                if ($minutesUntilEnd < 0) {
                    // Shift has ended
                    continue;
                }

                $minutesUntilStart = (int) now()->diffInMinutes($startTime);
                if ($minutesUntilStart < 0 && $minutesUntilEnd < config('work.reminder.check_out.before_minute')) {
                    // Shift has started and about to end
                    foreach ($shiftUsers as $shiftUser) {
                        if ($shiftUser->check_out_reminded_at || !$shiftUser->check_in_time) {
                            continue;
                        }

                        $shiftUser->check_out_reminded_at = now();
                        $shiftUser->save();
                        $notification = Notification::query()->create([
                            'type' => NotificationType::CHECK_OUT_REMINDER->value,
                            'title' => __('lang.reminder.check_out.title'),
                            'content' => __('lang.reminder.check_out.content'),
                            'icon' => NotificationIcon::WARNING->value,
                            'scheduled_at' => now(),
                            'user_id' => $shiftUser->user_id,
                            'data' => ['shift_user_id' => $shiftUser->id, 'check_in_id' => $shiftUser->check_in_id],
                        ]);
                        $notification->translations()->createMany(
                            array_map(
                                fn($lang) => [
                                    'lang' => $lang,
                                    'title' => __('lang.reminder.check_out.title', locale: $lang),
                                    'content' => __('lang.reminder.check_out.content', locale: $lang),
                                ],
                                Locale::getTranslationLocales()
                            )
                        );
                    }

                    continue;
                }

                $shiftReminderBefore = config('work.reminder.shift.before_minute');
                $checkInReminderBefore = config('work.reminder.check_in.before_minute');
                $finalConfirmationReminderBefore = config('work.reminder.confirmation.final_before_minute');
                if (
                    $minutesUntilStart > $shiftReminderBefore &&
                    $minutesUntilStart > $checkInReminderBefore &&
                    $minutesUntilStart > $finalConfirmationReminderBefore
                ) {
                    // Too early to remind
                    continue;
                }

                foreach ($shiftUsers as $shiftUser) {
                    if (!$shiftUser->shift_reminded_at && $minutesUntilStart <= $shiftReminderBefore) {
                        // Not reminded yet and about to start
                        $shiftUser->shift_reminded_at = now();
                        $shiftUser->save();
                        $date = Carbon::parse($shiftUser->date);
                        $notification = Notification::query()->create([
                            'type' => NotificationType::SHIFT_REMINDER->value,
                            'title' => __('lang.reminder.shift.title'),
                            'content' => __(
                                'lang.reminder.shift.content',
                                replace: [
                                    'date' => $date->format(__('lang.date_format')),
                                    'time' => $shiftUser->start_time,
                                ]
                            ),
                            'icon' => NotificationIcon::INFO->value,
                            'scheduled_at' => now(),
                            'user_id' => $shiftUser->user_id,
                            'data' => ['shift_user_id' => $shiftUser->id],
                        ]);
                        $notification->translations()->createMany(
                            array_map(
                                fn($lang) => [
                                    'lang' => $lang,
                                    'title' => __('lang.reminder.shift.title', locale: $lang),
                                    'content' => __(
                                        'lang.reminder.shift.content',
                                        replace: [
                                            'date' => $date->format(__('lang.date_format', locale: $lang)),
                                            'time' => $shiftUser->start_time,
                                        ],
                                        locale: $lang
                                    ),
                                ],
                                Locale::getTranslationLocales()
                            )
                        );
                    }

                    if (
                        !$shiftUser->check_in_reminded_at &&
                        $minutesUntilStart <= $checkInReminderBefore &&
                        !$shiftUser->check_in_time
                    ) {
                        // Not reminded yet and about to start and not checked in
                        $shiftUser->check_in_reminded_at = now();
                        $shiftUser->save();

                        $notification = Notification::query()->create([
                            'type' => NotificationType::CHECK_IN_REMINDER->value,
                            'title' => __('lang.reminder.check_in.title'),
                            'content' => __('lang.reminder.check_in.content'),
                            'icon' => NotificationIcon::WARNING->value,
                            'scheduled_at' => now(),
                            'user_id' => $shiftUser->user_id,
                            'data' => ['shift_user_id' => $shiftUser->id],
                        ]);
                        $notification->translations()->createMany(
                            array_map(
                                fn($lang) => [
                                    'lang' => $lang,
                                    'title' => __('lang.reminder.check_in.title', locale: $lang),
                                    'content' => __('lang.reminder.check_in.content', locale: $lang),
                                ],
                                Locale::getTranslationLocales()
                            )
                        );
                    }

                    if (
                        !$shiftUser->confirmed_at &&
                        !$shiftUser->final_confirmation_reminded_at &&
                        $minutesUntilStart >= 0 &&
                        $minutesUntilStart <= $finalConfirmationReminderBefore
                    ) {
                        // Not confirmed, not reminded yet, and within the final confirmation window
                        $shiftUser->final_confirmation_reminded_at = now();
                        $shiftUser->save();
                        $date = Carbon::parse($shiftUser->date);
                        $notification = Notification::query()->create([
                            'type' => NotificationType::CONFIRM_REMINDER->value,
                            'title' => __('lang.reminder.confirm.title'),
                            'content' => __(
                                'lang.reminder.confirm.content',
                                replace: ['date' => $date->format(__('lang.date_format'))]
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
                                        replace: ['date' => $date->format(__('lang.date_format', locale: $lang))],
                                        locale: $lang
                                    ),
                                ],
                                Locale::getTranslationLocales()
                            )
                        );
                    }
                }
            }
        });
    }
}
