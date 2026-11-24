<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Actions\Admin\Attendance\CheckInOutAction;
use App\Actions\Admin\Attendance\GetCurrentSessionAction;
use App\Actions\Admin\Attendance\PreviewNearbyWorkplaceAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Attendance\CurrentSessionRequest;
use App\Http\Requests\Admin\Attendance\MyHistoryRequest;
use App\Http\Requests\Admin\Attendance\SubmitRequest;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CheckInController extends Controller
{
    public function __construct(private readonly AdminAttendanceService $service) {}

    public function checkInOut(SubmitRequest $request, CheckInOutAction $action): JsonResponse
    {
        return $action($request->validated(), Auth::user())->toResponse();
    }

    /**
     * Mobile pre-check-in preview: nearest assigned workplace + distance, read-only.
     */
    public function previewNearby(SubmitRequest $request, PreviewNearbyWorkplaceAction $action): JsonResponse
    {
        return $action($request->validated(), Auth::user())->toResponse();
    }

    /**
     * Mobile: whether the acting admin currently has an open, valid session at a workplace,
     * independent of calendar-day bucketing (Requirement 1). Read-only.
     */
    public function currentSession(CurrentSessionRequest $request, GetCurrentSessionAction $action): JsonResponse
    {
        return $action($request->validated(), Auth::user())->toResponse();
    }

    /**
     * Mobile: the acting admin's own daily summary (level 1).
     */
    public function myHistory(MyHistoryRequest $request): JsonResponse
    {
        return ResponseHelper::successPaginated($this->service->getDailySummary($request->validated(), Auth::id()));
    }

    /**
     * Mobile: the acting admin's own sessions (with logs) for one day (level 2).
     */
    public function myDay(MyHistoryRequest $request): JsonResponse
    {
        $date = $request->validated()['date'] ?? now()->format('Y-m-d');

        return ResponseHelper::success($this->service->getDayDetail(Auth::id(), $date, Auth::id()));
    }
}
