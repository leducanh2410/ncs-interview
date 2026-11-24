<?php

namespace App\Actions\Common\Notification;

use App\Enums\ChatParticipantStatus;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\ChatParticipant;
use App\Models\Notification;
use App\Models\NotificationReadState;
use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Computes the app-icon badge number for a user: unread notifications plus unread
 * chat conversations. Sent as the APNs `aps.badge` so the iOS home-screen badge is
 * correct while the app is in background/terminated (foreground is handled in-app).
 *
 * Chat is counted per conversation (not per message) to mirror the mobile client's
 * foreground badge (totalUnreadCountProvider), keeping the two in sync. The unread
 * notification count follows NotificationService::countUnread(), including broadcast/system
 * notifications. Due PENDING notifications are also counted so the notification currently
 * being delivered is included before its status changes to SENT.
 *
 * Recruitment is the one type that is broadcast-shaped (user_id null) but not broadcast-
 * visible, so it is counted per recipient instead of as a shared total — see
 * unreadRecruitmentCounts().
 */
class CalculateUserBadgeCountAction
{
    public function execute(int $userId): int
    {
        return $this->executeForUsers([$userId])[$userId] ?? 0;
    }

    /**
     * Badge counts keyed by user id. Uses a fixed number of grouped queries regardless
     * of how many users are passed, so callers can resolve every recipient's badge once
     * before a send loop instead of querying per user (avoids N+1 on multi-recipient pushes).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public function executeForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return [];
        }

        $notifications = $this->unreadNotificationCounts($userIds);
        $conversations = $this->unreadConversationCounts($userIds);

        $result = [];
        foreach ($userIds as $userId) {
            $result[$userId] = ($notifications[$userId] ?? 0) + ($conversations[$userId] ?? 0);
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function unreadNotificationCounts(array $userIds): array
    {
        // Personal notifications the user hasn't read, grouped per owner.
        $personalQuery = Notification::query()->whereIn('user_id', $userIds);
        $this->scopeDelivered($personalQuery);
        $personal = $personalQuery
            ->whereDoesntHave('readStates', function (Builder $query) {
                $query->whereColumn('notification_read_states.user_id', 'notifications.user_id');
            })
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'user_id');

        // System notifications (user_id null) apply to every user; subtract those each has read.
        // Recruitment is excluded here and counted per user below: those rows are also
        // user_id null, but they are addressed to a resolved recipient list, so a campaign
        // aimed at one employee must not raise anyone else's badge.
        $systemQuery = Notification::query()->whereNull('user_id')->untargeted();
        $this->scopeDelivered($systemQuery);
        $systemTotal = $systemQuery->count();

        $systemRead = collect();
        if ($systemTotal > 0) {
            $systemRead = NotificationReadState::query()
                ->whereIn('user_id', $userIds)
                ->whereHas('notification', function (Builder $query) {
                    $query->whereNull('user_id')->untargeted();
                    $this->scopeDelivered($query);
                })
                ->groupBy('user_id')
                ->selectRaw('user_id, COUNT(*) as aggregate')
                ->pluck('aggregate', 'user_id');
        }

        $recruitment = $this->unreadRecruitmentCounts($userIds);

        $counts = [];
        foreach ($userIds as $userId) {
            $counts[$userId] =
                (int) ($personal[$userId] ?? 0) +
                ($systemTotal - (int) ($systemRead[$userId] ?? 0)) +
                (int) ($recruitment[$userId] ?? 0);
        }

        return $counts;
    }

    /**
     * Unread recruitment notifications, counted per user rather than as a shared total.
     *
     * Visibility mirrors Notification::scopeVisibleToUser: a resolved recipient row, or the
     * legacy escape hatch of a null recipients_resolved_count. Unlike the broadcast branch
     * this is restricted to SENT — a PENDING recruitment notification also has a null count,
     * simply because it has not been resolved yet, and counting it would hand every user a
     * badge for a campaign that may end up targeting none of them.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function unreadRecruitmentCounts(array $userIds): array
    {
        // Split by visibility rule, not by whether recipient rows happen to exist: a campaign
        // that resolved to nobody has a count of zero and no rows, and must stay invisible.
        //
        // The targeted branch is expressed as a join condition rather than by collecting
        // notification ids first: recruitment notifications are never cleaned up
        // (PruneNotificationsJob only touches personal ones), so a materialised id list would
        // grow with the whole history of the system and make every push pay for campaigns sent
        // years ago. The legacy branch below deliberately does the opposite — see there.
        $targetedQuery = NotificationRecipient::query()
            ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
            ->whereIn('notification_recipients.user_id', $userIds)
            ->whereNotNull('notifications.recipients_resolved_count')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('notification_read_states')
                    ->whereColumn('notification_read_states.notification_id', 'notification_recipients.notification_id')
                    ->whereColumn('notification_read_states.user_id', 'notification_recipients.user_id');
            });
        $this->scopeSentRecruitment($targetedQuery);
        $targeted = $targetedQuery
            ->groupBy('notification_recipients.user_id')
            ->selectRaw('notification_recipients.user_id as user_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'user_id');

        // Legacy: predates targeting, so it behaves like any other broadcast.
        //
        // Unlike the targeted branch, the ids ARE materialised here, because this set is
        // closed: since targeting shipped, every campaign resolves a recipient count before
        // it is marked SENT, so `recipients_resolved_count IS NULL AND status = SENT` can
        // never gain rows and the id list stays at its size on migration day. Pinning the
        // ids matters: joining read states against `notifications` instead lets MySQL drive
        // the join from the notifications side and scan every read state of every legacy
        // broadcast (~500k rows in production) on each badge calculation, where lookups on
        // the (user_id, notification_id) unique index touch a handful of rows per user.
        $legacyIdsQuery = Notification::query()->whereNull('notifications.recipients_resolved_count');
        $this->scopeSentRecruitment($legacyIdsQuery);
        $legacyIds = $legacyIdsQuery->pluck('id')->all();
        $legacyTotal = count($legacyIds);

        $legacyRead = collect();
        if ($legacyTotal > 0) {
            $legacyRead = NotificationReadState::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('notification_id', $legacyIds)
                ->groupBy('user_id')
                ->selectRaw('user_id, COUNT(*) as aggregate')
                ->pluck('aggregate', 'user_id');
        }

        $counts = [];
        foreach ($userIds as $userId) {
            $counts[$userId] = (int) ($targeted[$userId] ?? 0) + ($legacyTotal - (int) ($legacyRead[$userId] ?? 0));
        }

        return $counts;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function unreadConversationCounts(array $userIds): array
    {
        return ChatParticipant::query()
            ->whereIn('user_id', $userIds)
            ->where('status', ChatParticipantStatus::Active->value)
            ->where('unread_count', '>', 0)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'user_id')
            ->map(static fn($count) => (int) $count)
            ->all();
    }

    /**
     * A recruitment notification that has gone out, addressed with fully qualified columns so
     * the same conditions can be applied to `notifications` directly or to a table joined
     * against it.
     */
    private function scopeSentRecruitment(Builder $query): void
    {
        $query
            ->whereNull('notifications.user_id')
            ->where(function (Builder $targeted) {
                $targeted
                    ->where('notifications.type', NotificationType::RECRUITMENT->value)
                    ->orWhere('notifications.target_type', NotificationTargetType::Workplace->value);
            })
            ->where('notifications.status', NotificationStatus::SENT);
    }

    /**
     * SENT = already delivered; PENDING-and-due = being delivered in this run, so the badge
     * includes the notification that triggered this push (still PENDING at send time).
     */
    private function scopeDelivered(Builder $query): void
    {
        $query->where(function (Builder $inner) {
            $inner->where('status', NotificationStatus::SENT)->orWhere(function (Builder $due) {
                $due->where('status', NotificationStatus::PENDING)->where('scheduled_at', '<=', now());
            });
        });
    }
}
