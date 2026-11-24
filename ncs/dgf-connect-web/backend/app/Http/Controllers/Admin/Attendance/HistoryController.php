<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Actions\Admin\Attendance\ExportMonthlyAttendanceAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Attendance\DayDetailRequest;
use App\Http\Requests\Admin\Attendance\IndexRequest;
use App\Http\Requests\Admin\Attendance\MonthlyExportRequest;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HistoryController extends Controller
{
    public function __construct(private readonly AdminAttendanceService $service) {}

    /**
     * Level 1 — paginated daily summary (one row per admin + day).
     * GlobalAdmin sees all admins.
     */
    public function daily(IndexRequest $request): JsonResponse
    {
        return ResponseHelper::successPaginated($this->service->getDailySummary($request->validated()));
    }

    /**
     * Level 2 — sessions (with event logs) for one admin on one day.
     */
    public function day(DayDetailRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return ResponseHelper::success($this->service->getDayDetail((int) $validated['user_id'], $validated['date']));
    }

    public function export(
        MonthlyExportRequest $request,
        ExportMonthlyAttendanceAction $action
    ): BinaryFileResponse|JsonResponse {
        $validated = $request->validated();
        $userIds = array_map('intval', $validated['user_ids'] ?? []);

        $result = $action->execute($validated['month'], $userIds);
        if (!$result) {
            return ResponseHelper::badRequest();
        }

        return Excel::download($result['report'], $result['filename']);
    }
}
