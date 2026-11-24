<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FixedSchedule\IndexRequest;
use App\Http\Requests\Admin\FixedSchedule\StoreRequest;
use App\Models\FixedSchedule;
use App\Services\Admin\FixedScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FixedScheduleController extends Controller
{
    private readonly FixedScheduleService $fixedScheduleService;

    public function __construct(FixedScheduleService $fixedScheduleService)
    {
        $this->fixedScheduleService = $fixedScheduleService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $params = $request->validated();

        return ResponseHelper::successPaginated($this->fixedScheduleService->listFixedSchedules(Auth::user(), $params));
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $result = $this->fixedScheduleService->store(Auth::user(), $request->validated());
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success($result['data']);
    }

    public function update(FixedSchedule $schedule, StoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['fixed_schedule_id'] = $schedule->id;

        $result = $this->fixedScheduleService->update(Auth::user(), $schedule, $validated);
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success($result['data']);
    }

    public function destroy(FixedSchedule $schedule): JsonResponse
    {
        $result = $this->fixedScheduleService->destroy(Auth::user(), $schedule);
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success($result['data']);
    }
}
