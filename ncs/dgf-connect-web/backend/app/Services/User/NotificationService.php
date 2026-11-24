<?php

namespace App\Services\User;

use App\Enums\ErrorCode;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class NotificationService
{
    public const SYSTEM_NOTIFICATIONS_CACHE_KEY = 'system_notifications:v2';

    public function getNotifications(): LengthAwarePaginator
    {
        return Notification::query()
            ->withExists([
                'readStates as is_read' => function (Builder $query) {
                    $query->where('user_id', Auth::id());
                },
            ])
            ->with('translations')
            ->where(function (Builder $query) {
                $query->where('user_id', Auth::id())->orWhereNull('user_id');
            })
            ->visibleToUser((int) Auth::id())
            ->where('status', NotificationStatus::SENT)
            ->orderByDesc('processed_at')
            ->paginate();
    }

    public function getLatestSystemNotifications(): Collection
    {
        $notifications = Cache::rememberForever(self::SYSTEM_NOTIFICATIONS_CACHE_KEY, function (): array {
            return Notification::query()
                ->with('translations')
                ->whereNull('user_id')
                // Recruitment notifications are excluded here rather than filtered per user
                // afterwards: this cache uses one key shared by every caller, so whatever
                // enters it is served to everyone and no per-user scoping can undo that.
                // Their recipients still see them through the regular, scoped list.
                ->untargeted()
                ->where('status', NotificationStatus::SENT)
                ->orderByDesc('processed_at')
                ->limit(5)
                ->get()
                ->map(fn(Notification $notification): array => $notification->toArray())
                ->all();
        });

        if ($notifications === []) {
            return collect();
        }

        $notifications = collect($notifications);
        $userId = Auth::id();
        $readNotificationIds = Notification::query()
            ->whereIn('id', $notifications->pluck('id'))
            ->whereHas('readStates', fn(Builder $query) => $query->where('user_id', $userId))
            ->pluck('id')
            ->toArray();

        return $notifications->map(function (array $notification) use ($readNotificationIds): array {
            $notification['is_read'] = in_array($notification['id'], $readNotificationIds, true);

            return $notification;
        });
    }

    public function countUnread(): int
    {
        return Notification::query()
            ->where(function (Builder $query) {
                $query->where('user_id', Auth::id())->orWhereNull('user_id');
            })
            ->visibleToUser((int) Auth::id())
            ->where('status', NotificationStatus::SENT)
            ->whereDoesntHave('readStates', function (Builder $query) {
                $query->where('user_id', Auth::id());
            })
            ->count();
    }

    public function readNotification(Notification $notification): array
    {
        if ($notification->user_id !== null && $notification->user_id !== Auth::id()) {
            return [
                'error' => ErrorCode::Forbidden,
                'status' => 403,
            ];
        }

        // A broadcast row is not automatically everyone's to read any more: a targeted
        // recruitment notification is only readable by the people it was resolved for.
        if (!$notification->isVisibleToUser((int) Auth::id())) {
            return [
                'error' => ErrorCode::Forbidden,
                'status' => 403,
            ];
        }

        $notification->readStates()->updateOrCreate(['user_id' => Auth::id()], ['read_at' => now()]);

        return [
            'error' => null,
            'data' => null,
        ];
    }

    public function readAllNotifications(): true
    {
        $userId = Auth::id();
        $notifications = Notification::query()
            ->where(function (Builder $query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })
            // Without this, "mark all as read" would create read states for recruitment
            // notifications the employee was never targeted for, inflating the admin's
            // read count past the number of people the notification actually reached.
            ->visibleToUser((int) $userId)
            ->where('status', NotificationStatus::SENT)
            ->whereDoesntHave('readStates', function (Builder $query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->get();

        if ($notifications->isEmpty()) {
            return true;
        }

        foreach ($notifications as $notification) {
            $notification->readStates()->create([
                'user_id' => $userId,
                'read_at' => now(),
            ]);
        }

        return true;
    }
}
