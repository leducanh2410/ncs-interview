<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\Shift\ToggleAbsentAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\Schedule\GetUserScheduleRequest;
use App\Services\Manage\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ScheduleController extends Controller
{
    private readonly ScheduleService $scheduleService;

    public function __construct(ScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    public function getUserSchedule(int $userId, GetUserScheduleRequest $request): JsonResponse
    {
        $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
        $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

        $result = $this->scheduleService->getUserSchedule($userId, $startDate, $endDate);
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function toggleAbsent(int $shiftUserId, ToggleAbsentAction $action): JsonResponse
    {
        $result = $action($shiftUserId);

        return $result->toResponse();
    }
}
