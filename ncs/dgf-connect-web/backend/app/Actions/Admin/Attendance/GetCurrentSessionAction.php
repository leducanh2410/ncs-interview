<?php

namespace App\Actions\Admin\Attendance;

use App\Actions\ActionResult;
use App\Models\AdminAttendanceSession;
use App\Models\User;
use App\Services\Admin\AdminAttendanceService;

/**
 * Read-only lookup answering "does this admin currently have an open, valid session at this
 * workplace" (Requirement 1), independent of calendar-day bucketing. Also reports an
 * un-invalidated Stale_Session when the only open session at the workplace has missed its
 * Valid_Checkout_Window, so a client can tell "never checked in" from "the previous session just
 * expired" (Requirement 1 AC4). Never mutates state — including for a reported Stale_Session,
 * which stays un-invalidated until the next check-in/check-out request (Requirement 1 AC5).
 *
 * `next_action` (`'check_in'` | `'check_out'`) states the client's button action directly —
 * derived here from the same `$session` presence a client could infer for itself, but stated
 * explicitly so no client re-implements that inference (or is tempted to key it off something
 * less reliable, like `stale_session` being present).
 */
readonly class GetCurrentSessionAction
{
    public function __construct(private AdminAttendanceService $service) {}

    public function __invoke(array $data, User $admin): ActionResult
    {
        $workplaceId = (int) $data['workplace_id'];
        $now = now();

        $session = $this->service->findOpenSession($admin->id, $workplaceId, $now);
        if ($session) {
            $session->load('workplace:id,name,address');

            return new ActionResult(
                data: $session->toArray(),
                meta: ['next_action' => 'check_out', 'stale_session' => null]
            );
        }

        $staleSession = $this->service->findStaleOpenSession($admin->id, $workplaceId, $now, lock: false);

        return new ActionResult(
            data: null,
            meta: [
                'next_action' => 'check_in',
                'stale_session' => $staleSession ? $this->staleSessionReport($staleSession) : null,
            ]
        );
    }

    private function staleSessionReport(AdminAttendanceSession $staleSession): array
    {
        return [
            'id' => $staleSession->id,
            'workplace_id' => $staleSession->workplace_id,
            'work_date' => $staleSession->work_date->format('Y-m-d'),
            // The check-in that actually went stale — `latestCheckInAt()`, not raw
            // `first_check_in_at` — so this pairs correctly with `checkout_deadline_at` below
            // (both anchored on the same check-in) instead of describing two different events
            // after a same-day Checkpoint_Commit re-check-in.
            'first_check_in_at' => $staleSession->latestCheckInAt()->toIso8601String(),
            'checkout_deadline_at' => $staleSession->checkout_deadline_at,
        ];
    }
}
