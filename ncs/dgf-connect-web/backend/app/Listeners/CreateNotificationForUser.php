<?php

namespace App\Listeners;

use App\Enums\Locale;
use App\Enums\NotificationIcon;
use App\Enums\NotificationType;
use App\Events\AbsentNoticeRejected;
use App\Events\ShiftUserApproved;
use App\Events\ShiftUserRejected;
use App\Mail\ShiftUserScheduledEmail;
use App\Models\Notification;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CreateNotificationForUser
{
    public function handleShiftUserApproved(ShiftUserApproved $event): void
    {
        DB::transaction(function () use ($event) {
            $notificationData = [
                'type' => NotificationType::SHIFT_APPROVED->value,
                'title' => __('lang.shift_approved.title'),
                'content' => __(
                    'lang.shift_approved.content',
                    replace: ['date' => $event->shiftDate->format(__('lang.date_format'))]
                ),
                'icon' => NotificationIcon::INFO->value,
                'scheduled_at' => now(),
                'user_id' => $event->shiftUser->user_id,
                'data' => [
                    'shift_user_id' => $event->shiftUser->id,
                ],
            ];
            $notification = Notification::query()->create($notificationData);
            $notification->translations()->createMany(
                array_map(
                    fn($lang) => [
                        'lang' => $lang,
                        'title' => __('lang.shift_approved.title', locale: $lang),
                        'content' => __(
                            'lang.shift_approved.content',
                            replace: ['date' => $event->shiftDate->format(__('lang.date_format', locale: $lang))],
                            locale: $lang
                        ),
                    ],
                    Locale::getTranslationLocales()
                )
            );
        });

        $user = $event->shiftUser->user;
        $shiftDate = $event->shiftDate;
        Mail::to($user->email)->send(
            new ShiftUserScheduledEmail($user->short_name, $shiftDate->format(__('lang.date_format')))
        );
    }

    public function handleShiftUserRejected(ShiftUserRejected $event): void
    {
        if ($event->shiftUser->created_by) {
            // Do not send notification if created_by has value (created by manager)
            return;
        }

        DB::transaction(function () use ($event) {
            $notificationData = [
                'type' => NotificationType::SHIFT_REJECTED->value,
                'title' => __('lang.shift_rejected.title'),
                'content' => __(
                    'lang.shift_rejected.content',
                    replace: ['date' => $event->shiftDate->format(__('lang.date_format'))]
                ),
                'icon' => NotificationIcon::INFO->value,
                'scheduled_at' => now(),
                'user_id' => $event->shiftUser->user_id,
                'data' => [
                    'shift_user_id' => $event->shiftUser->id,
                ],
            ];
            $notification = Notification::query()->create($notificationData);
            $notification->translations()->createMany(
                array_map(
                    fn($lang) => [
                        'lang' => $lang,
                        'title' => __('lang.shift_rejected.title', locale: $lang),
                        'content' => __(
                            'lang.shift_rejected.content',
                            replace: ['date' => $event->shiftDate->format(__('lang.date_format', locale: $lang))],
                            locale: $lang
                        ),
                    ],
                    Locale::getTranslationLocales()
                )
            );
        });
    }

    public function handleAbsentNoticeRejected(AbsentNoticeRejected $event): void
    {
        DB::transaction(function () use ($event) {
            $notificationData = [
                'type' => NotificationType::ABSENT_NOTICE_REJECTED->value,
                'title' => __('lang.absent_notice_rejected.title'),
                'content' => __(
                    'lang.absent_notice_rejected.content',
                    replace: ['date' => $event->shiftDate->format(__('lang.date_format'))]
                ),
                'icon' => NotificationIcon::INFO->value,
                'scheduled_at' => now(),
                'user_id' => $event->absentNotice->shiftUser->user_id,
                'data' => [
                    'shift_user_id' => $event->absentNotice->shift_user_id,
                ],
            ];
            $notification = Notification::query()->create($notificationData);
            $notification->translations()->createMany(
                array_map(
                    fn($lang) => [
                        'lang' => $lang,
                        'title' => __('lang.absent_notice_rejected.title', locale: $lang),
                        'content' => __(
                            'lang.absent_notice_rejected.content',
                            replace: ['date' => $event->shiftDate->format(__('lang.date_format', locale: $lang))],
                            locale: $lang
                        ),
                    ],
                    Locale::getTranslationLocales()
                )
            );
        });
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            ShiftUserApproved::class => 'handleShiftUserApproved',
            ShiftUserRejected::class => 'handleShiftUserRejected',
            AbsentNoticeRejected::class => 'handleAbsentNoticeRejected',
        ];
    }
}
