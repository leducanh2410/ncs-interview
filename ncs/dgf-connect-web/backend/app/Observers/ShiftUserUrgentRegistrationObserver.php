<?php

namespace App\Observers;

use App\Enums\NotificationType;
use App\Models\NotificationReadState;
use App\Models\ShiftUser;

/**
 * Flags a registration that was prompted by a recruitment notification.
 *
 * The signal is entirely server-derived: if the employee had already read a recruitment
 * notification for this shift and a registration for it is then created, that counts as
 * answering the call. Nothing is sent by the client, which is what keeps the employee
 * mobile app — outside this repository — free of any change.
 *
 * How the row came to exist is deliberately not part of the rule (requirement 1.7): the
 * marking depends only on the employee having read the notification beforehand, so admin
 * assignment, CSV import and fixed-schedule generation are all marked on the same terms as
 * a self-registration. Hooking `created` on the model is what makes that hold for every
 * creation path with one implementation.
 *
 * Only `created` is handled. That gives requirements 1.5 and 1.6 for free: a read arriving
 * after the registration cannot reach back and flag it, and approving, rejecting or
 * cancelling the registration later never revisits the flag.
 */
class ShiftUserUrgentRegistrationObserver
{
    public function created(ShiftUser $shiftUser): void
    {
        if ($shiftUser->user_id === null || $shiftUser->shift_id === null) {
            return;
        }

        if (!$this->hasReadRecruitmentNotificationForShift((int) $shiftUser->user_id, (int) $shiftUser->shift_id)) {
            return;
        }

        // saveQuietly: this is a derived attribute, not a domain event. Re-entering the
        // observer chain here would re-run every other ShiftUser listener for a value the
        // user did not set.
        $shiftUser->is_urgent_registration = true;
        $shiftUser->saveQuietly();
    }

    /**
     * Deliberately driven from notification_read_states rather than from notifications.
     *
     * notifications.shift_id was dropped as a column (migration 2025_02_14_131127) and now
     * lives inside the data JSON, where no index can reach it. Starting from notifications
     * would therefore scan that whole table once per registration — and admin assignment
     * creates many registrations in one action. Starting from this user's read states uses
     * the user_id index, leaving only a handful of rows for the JSON comparison to touch.
     *
     * No read_at comparison is needed: the observer runs as the row is created, so every
     * read state that exists at this moment necessarily predates the registration.
     */
    private function hasReadRecruitmentNotificationForShift(int $userId, int $shiftId): bool
    {
        return NotificationReadState::query()
            ->where('notification_read_states.user_id', $userId)
            ->join('notifications', 'notifications.id', '=', 'notification_read_states.notification_id')
            ->where('notifications.type', NotificationType::RECRUITMENT->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.shift_id')) = ?", [(string) $shiftId])
            ->exists();
    }
}
