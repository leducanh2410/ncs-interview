<?php

namespace App\Services\Manage;

use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Shift;
use App\ValueObjects\RecruitmentCriteria;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function getNotificationList(array $conditions): LengthAwarePaginator
    {
        if (isset($conditions['dates'])) {
            $conditions['dates'][1] = Carbon::parse($conditions['dates'][1])->endOfDay();
        } else {
            $conditions['dates'] = [now()->startOfMonth(), now()->endOfMonth()];
        }

        $data = Notification::getFilteredQuery($conditions, ['translations', 'workplaces:id,name'])
            ->whereNull('user_id')
            // The denominator is the stored recipients_resolved_count, so only the numerator
            // needs an aggregate.
            ->withCount([
                'readStates as read_count' => fn($query) => $query->where(function ($inner) {
                    $inner
                        // Recruitment is addressed to a resolved list, so only those people
                        // count: mark-all-as-read historically created read states for
                        // employees who were never targeted, and counting those would push
                        // the fraction above 100%.
                        ->whereIn(
                            'notification_read_states.user_id',
                            fn($sub) => $sub
                                ->select('user_id')
                                ->from('notification_recipients')
                                ->whereColumn('notification_recipients.notification_id', 'notifications.id')
                        )
                        // Every other type is a true broadcast with no recipient list, so
                        // every read counts. Without this the subquery matches nothing and
                        // the count silently reads zero for all of them.
                        ->orWhere(function ($untargeted) {
                            $untargeted
                                ->where('notifications.type', '!=', NotificationType::RECRUITMENT->value)
                                ->where('notifications.target_type', NotificationTargetType::All->value);
                        });
                }),
            ])
            ->paginateFilter($conditions['page_size'] ?? null);
        $shiftIds = $data->pluck('data.shift_id')->filter()->unique()->toArray();
        $shifts = Shift::query()->with('workplaceShift.workplace')->whereIn('id', $shiftIds)->get()->keyBy('id');

        $data->through(function ($notification) use ($shifts) {
            if ($notification->data && $notification->data['shift_id']) {
                $notification->shift = $shifts[$notification->data['shift_id']];
            }

            return $notification;
        });

        return $data;
    }

    public function createOrUpdateNotification(?Notification $notification, array $values): array
    {
        if ($notification) {
            if ($notification->status === NotificationStatus::SENT->value) {
                return [
                    'error' => ErrorCode::NotificationAlreadySent,
                    'status' => 400,
                    'data' => null,
                ];
            }
            if ($notification->user_id) {
                return [
                    'error' => ErrorCode::NotFound,
                    'status' => 404,
                    'data' => null,
                ];
            }
        }

        $values['user_id'] = null;
        if (!$notification) {
            $values['status'] = NotificationStatus::PENDING->value;
        }

        $type = NotificationType::from($values['type']);
        $workplaceIds = [];
        if (
            $type->supportsWorkplaceTarget() &&
            ($values['target_type'] ?? null) === NotificationTargetType::Workplace->value
        ) {
            $values['target_type'] = NotificationTargetType::Workplace->value;
            $workplaceIds = array_values(array_unique(array_map('intval', $values['workplace_ids'] ?? [])));
        } else {
            $values['target_type'] = NotificationTargetType::All->value;
        }
        unset($values['workplace_ids']);

        if ($type === NotificationType::BANK_ACCOUNT_REQUIRED) {
            $values['url'] = null;
        }

        if ($values['type'] === NotificationType::RECRUITMENT->value) {
            if ($notification) {
                $values['icon'] = null;
                $values['url'] = null;
            }

            $shiftId = (int) $values['shift_id'];
            $workplaceId = Shift::query()->find($shiftId)->workplace_id;

            // The targeting criteria ride along in the same JSON blob as shift/workplace.
            // They are written once and read as a unit when the notification is sent, so
            // they never need to be filtered or joined on and earn no columns of their own.
            $values['data'] = RecruitmentCriteria::fromArray(
                array_merge($values, ['shift_id' => $shiftId, 'workplace_id' => $workplaceId])
            )->toArray();
            unset($values['shift_id']);
        }

        // Criteria submitted with any other type are discarded rather than rejected, the
        // same way icon and url are dropped above when the type is recruitment.
        unset(
            $values['gender_target'],
            $values['requires_experience'],
            $values['include_nearby_workplaces'],
            $values['nearby_radius_km'],
            $values['include_frequent_urgent_group'],
            $values['hot_bonus_amount']
        );

        if ($values['is_manually_scheduled']) {
            $values['scheduled_at'] = Carbon::parse($values['date'] . ' ' . $values['time']);
        } else {
            if (!$notification || $notification->is_manually_scheduled) {
                $values['scheduled_at'] = now();
            }
        }

        unset($values['date'], $values['time']);

        $englishLocale = Locale::English->value;
        $hasEnglishTranslation = isset($values["title_{$englishLocale}"]);
        $fallbackTitle = $values["title_{$englishLocale}"] ?? $values['title'];
        $fallbackContent = $values["content_{$englishLocale}"] ?? $values['content'];
        $fallbackSourceLang = $hasEnglishTranslation ? $englishLocale : config('app.base_locale');

        // Ensure every non-default locale has a translation row, so topic subscribers for
        // locales the admin didn't fill in (e.g. ne/si) still receive a push. The fallback is
        // the admin-provided English translation when present, otherwise the original
        // title/content fields as-is (which are not guaranteed to be in English). is_fallback
        // marks which rows are this filler content rather than a genuine translation, and
        // fallback_source_lang records which language it actually was copied from, so
        // consumers can still show it (it's real, readable content) without mislabeling it as
        // the recipient's own language — e.g. pairing it with a bonus line in a mismatched
        // locale.
        $translations = [];
        foreach (Locale::getBaseTranslationLocales() as $locale) {
            $hasOwnTranslation = isset($values["title_{$locale}"]);
            $translations[] = [
                'lang' => $locale,
                'title' => $values["title_{$locale}"] ?? $fallbackTitle,
                'content' => $values["content_{$locale}"] ?? $fallbackContent,
                'is_fallback' => !$hasOwnTranslation,
                'fallback_source_lang' => $hasOwnTranslation ? null : $fallbackSourceLang,
            ];
            unset($values["title_{$locale}"], $values["content_{$locale}"]);
        }

        DB::transaction(function () use (&$notification, $values, $translations, $workplaceIds) {
            $notification = Notification::query()->updateOrCreate(['id' => $notification?->id], $values);
            $notification->translations()->delete();
            if (!empty($translations)) {
                $notification->translations()->createMany($translations);
            }
            $notification->workplaces()->sync($workplaceIds);
        });

        return [
            'error' => null,
            'data' => $notification->load('workplaces:id,name'),
        ];
    }

    public function deleteNotification(Notification $notification): array
    {
        if ($notification->status === NotificationStatus::SENT->value) {
            return [
                'error' => ErrorCode::NotificationAlreadySent,
                'status' => 400,
                'data' => null,
            ];
        }
        if ($notification->user_id) {
            return [
                'error' => ErrorCode::NotFound,
                'status' => 404,
                'data' => null,
            ];
        }

        return [
            'error' => null,
            'data' => $notification->delete(),
        ];
    }
}
