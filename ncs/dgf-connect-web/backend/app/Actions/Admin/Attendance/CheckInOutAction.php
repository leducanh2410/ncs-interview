<?php

namespace App\Actions\Admin\Attendance;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\AdminAttendanceSession;
use App\Models\User;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for the mobile check-in/out button: the backend decides whether this
 * submission is a check-in or a check-out from the admin's currently open session among their
 * assigned workplaces within range (`resolveCheckInOrOutTarget()`), so the client never states
 * which one it means.
 */
readonly class CheckInOutAction
{
    public function __construct(private AdminAttendanceService $service) {}

    public function __invoke(array $data, User $admin): ActionResult
    {
        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        $resolution = $this->service->resolveCheckInOrOutTarget($admin, $latitude, $longitude);
        if (isset($resolution['error'])) {
            return new ActionResult(error: $resolution['error'], status: 400);
        }

        $coordinate = "{$latitude},{$longitude}";
        $now = now();

        if ($resolution['action'] === 'check_out') {
            return $this->checkOut($resolution['session'], $coordinate, $now);
        }

        return $this->checkIn($admin->id, $resolution['workplace']->id, $coordinate, $now);
    }

    private function checkOut(AdminAttendanceSession $session, string $coordinate, Carbon $now): ActionResult
    {
        if (!$now->isAfter(Carbon::parse($session->first_check_in_at))) {
            return new ActionResult(error: ErrorCode::CheckOutTimeInvalid, status: 400);
        }

        $session = DB::transaction(fn() => $this->service->persistCheckOut($session, $coordinate, $now));
        $session->load('workplace:id,name,address');

        return new ActionResult(data: $session->toArray());
    }

    private function checkIn(int $adminId, int $workplaceId, string $coordinate, Carbon $now): ActionResult
    {
        $result = DB::transaction(fn() => $this->service->persistCheckIn($adminId, $workplaceId, $coordinate, $now));
        $session = $result['session'];
        $invalidatedSession = $result['invalidatedSession'];

        $session->load('workplace:id,name,address');

        $meta = $invalidatedSession
            ? ['warning' => $this->service->staleSessionWarningPayload($invalidatedSession)]
            : null;

        return new ActionResult(data: $session->toArray(), meta: $meta);
    }
}
