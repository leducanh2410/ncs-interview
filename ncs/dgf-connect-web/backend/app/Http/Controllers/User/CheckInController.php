<?php

namespace App\Http\Controllers\User;

use App\Actions\User\CheckIn\CheckInAction;
use App\Actions\User\CheckIn\CheckOutAction;
use App\Actions\User\CheckIn\SubmitAttendanceReasonAction;
use App\Enums\AttendanceStatus;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\CheckIn\GetHistoryRequest;
use App\Http\Requests\User\CheckIn\GetReportsRequest;
use App\Http\Requests\User\CheckIn\SubmitAttendanceReasonRequest;
use App\Http\Requests\User\CheckIn\SubmitRequest;
use App\Services\User\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CheckInController extends Controller
{
    private readonly CheckInService $checkInService;

    public function __construct(CheckInService $checkInService)
    {
        $this->checkInService = $checkInService;
    }

    public function getCheckInShift(): JsonResponse
    {
        $shiftUser = $this->checkInService->getCheckInShift();
        $shiftUser?->checkIn?->withReasonRequirements();

        return ResponseHelper::success($shiftUser);
    }

    public function checkIn(SubmitRequest $request, CheckInAction $action): JsonResponse
    {
        $result = $action($request->validated());

        return $result->toResponse();
    }

    public function checkOut(SubmitRequest $request, CheckOutAction $action): JsonResponse
    {
        $result = $action($request->validated());

        return $result->toResponse();
    }

    public function submitAttendanceReason(
        int $checkInId,
        SubmitAttendanceReasonRequest $request,
        SubmitAttendanceReasonAction $action
    ): JsonResponse {
        $data = $request->validated();

        return $action($checkInId, Auth::id(), AttendanceStatus::from($data['type']), $data['reason'])->toResponse();
    }

    public function getHistory(GetHistoryRequest $request): JsonResponse
    {
        $month = $request->validated()['month'];
        $startDate = Carbon::parse($month)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $result = $this->checkInService->getHistory($startDate, $endDate);

        return ResponseHelper::success($result);
    }

    public function getCheckInDetail(int $checkInId): JsonResponse
    {
        $checkIn = $this->checkInService->getCheckInDetail($checkInId);
        if (!$checkIn) {
            return ResponseHelper::notFound();
        }

        return ResponseHelper::success($checkIn);
    }

    public function getReports(GetReportsRequest $request): JsonResponse
    {
        $month = $request->validated()['month'];
        $startDate = Carbon::parse($month)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $result = $this->checkInService->getReports($startDate, $endDate);

        return ResponseHelper::success($result);
    }

    public function getReportDetail(int $reportId): JsonResponse
    {
        $report = $this->checkInService->getReportDetail($reportId);
        if (!$report) {
            return ResponseHelper::notFound();
        }

        return ResponseHelper::success($report);
    }
}
