<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Schedule\CancelShiftAction;
use App\Actions\User\Schedule\CreateAbsentNoticeAction;
use App\Actions\User\Schedule\RegisterShiftAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\Schedule\IndexRequest;
use App\Http\Requests\User\Schedule\SearchShiftRequest;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Services\User\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ScheduleController extends Controller
{
    private readonly ScheduleService $scheduleService;

    public function __construct(ScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    public function getListUserSchedules(IndexRequest $request): JsonResponse
    {
        return ResponseHelper::success($this->scheduleService->getListSchedules($request->validated()));
    }

    public function confirmSchedule(ShiftUser $schedule): JsonResponse
    {
        $result = $this->scheduleService->confirmSchedule($schedule);
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function searchShifts(SearchShiftRequest $request): JsonResponse
    {
        $date = Carbon::parse($request->input('date'));
        $yesterday = Carbon::yesterday();
        $conditions = $request->validated();
        if ($date->lt($yesterday)) {
            $conditions['date'] = $yesterday->format('Y-m-d');
        }

        return ResponseHelper::success($this->scheduleService->searchShifts($conditions));
    }

    public function registerShift(Shift $shift, RegisterShiftAction $action): JsonResponse
    {
        return $action($shift)->toResponse();
    }

    public function getShiftDetail(Shift $shift): JsonResponse
    {
        $result = $this->scheduleService->getShiftDetail($shift);
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function getShiftUserDetail(ShiftUser $shiftUser): JsonResponse
    {
        if ($shiftUser->user_id !== Auth::id()) {
            return ResponseHelper::forbidden();
        }

        return ResponseHelper::success($this->scheduleService->getShiftUserDetail($shiftUser));
    }

    public function createAbsentNotice(ShiftUser $shiftUser, CreateAbsentNoticeAction $action): JsonResponse
    {
        return $action($shiftUser, Auth::user())->toResponse();
    }

    public function cancelShift(ShiftUser $shiftUser, CancelShiftAction $action): JsonResponse
    {
        return $action($shiftUser, Auth::user())->toResponse();
    }
}
