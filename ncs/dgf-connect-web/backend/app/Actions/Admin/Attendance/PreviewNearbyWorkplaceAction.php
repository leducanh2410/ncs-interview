<?php

namespace App\Actions\Admin\Attendance;

use App\Actions\ActionResult;
use App\Models\User;
use App\Services\Admin\AdminAttendanceService;

/**
 * Read-only preview of which assigned workplace is nearest to the admin's current GPS position,
 * with the distance and whether it's within range — lets the mobile app show workplace info
 * before the admin attempts a check-in/check-out, instead of only finding out via an error after
 * submitting. Persists nothing.
 */
readonly class PreviewNearbyWorkplaceAction
{
    public function __construct(private AdminAttendanceService $service) {}

    public function __invoke(array $data, User $admin): ActionResult
    {
        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        $result = $this->service->previewNearestWorkplace($admin, $latitude, $longitude);
        if (isset($result['error'])) {
            return new ActionResult(error: $result['error'], status: 400);
        }

        return new ActionResult(
            data: [
                'workplace' => [
                    'id' => $result['workplace']->id,
                    'name' => $result['workplace']->name,
                    'address' => $result['workplace']->address,
                ],
                'distance_meters' => $result['distanceMeters'],
                'max_distance_meters' => $result['maxDistanceMeters'],
                'within_range' => $result['withinRange'],
            ]
        );
    }
}
