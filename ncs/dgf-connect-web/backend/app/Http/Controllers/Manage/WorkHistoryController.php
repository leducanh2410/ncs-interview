<?php

namespace App\Http\Controllers\Manage;

use App\Actions\Manage\WorkHistory\AddUsersToShiftAction;
use App\Actions\Manage\WorkHistory\CreateWorkHistoryExportAction;
use App\Actions\Manage\WorkHistoryEditRequest\CreateWorkHistoryEditRequestAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\WorkHistory\AddUsersToShiftRequest;
use App\Http\Requests\Manage\WorkHistory\CreateEditRequestRequest;
use App\Http\Requests\Manage\WorkHistory\IndexRequest;
use App\Http\Requests\Manage\WorkHistory\MonthIndexRequest;
use App\Models\CheckIn;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkHistoryController extends Controller
{
    private readonly WorkHistoryService $workHistoryService;

    public function __construct(WorkHistoryService $workHistoryService)
    {
        $this->workHistoryService = $workHistoryService;
    }

    public function getWorkHistories(IndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        if (empty($conditions['dates'])) {
            $conditions['dates'] = [now()->subDays(7), now()];
        }

        return ResponseHelper::success($this->workHistoryService->getWorkHistory($conditions, Auth::user()));
    }

    public function getMonthlyWorkHistories(MonthIndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        if (empty($conditions['month'])) {
            $conditions['month'] = now()->format('Y-m');
        }

        return ResponseHelper::success($this->workHistoryService->getMonthWorkHistory($conditions, Auth::user()));
    }

    public function updateCheckIn(
        CheckIn $checkIn,
        CreateEditRequestRequest $request,
        CreateWorkHistoryEditRequestAction $action
    ): JsonResponse {
        return $action(Auth::user(), $checkIn, $request->validated())->toResponse();
    }

    public function addUsersToShift(AddUsersToShiftRequest $request, AddUsersToShiftAction $action): JsonResponse
    {
        return $action($request->input('shift_id'), $request->input('user_ids'))->toResponse();
    }

    public function exportDetailReport(IndexRequest $request, CreateWorkHistoryExportAction $action): JsonResponse
    {
        $conditions = $request->validated();
        if (empty($conditions['dates'])) {
            $conditions['dates'] = [now()->subDays(7)->toDateString(), now()->toDateString()];
        }
        unset($conditions['page_size']);

        $batch = $action->execute(Auth::user(), $conditions);
        if (!$batch) {
            return ResponseHelper::badRequest();
        }

        $batch->load('exporter:id,first_name,last_name,short_name');

        return ResponseHelper::success($batch, 202);
    }

    public function exportMonthlyReport(MonthIndexRequest $request): BinaryFileResponse|JsonResponse
    {
        $conditions = $request->validated();
        if (empty($conditions['month'])) {
            $conditions['month'] = now()->format('Y-m');
        }

        $result = $this->workHistoryService->exportMonthlyReport($conditions, Auth::user());
        if (!$result) {
            return ResponseHelper::badRequest();
        }

        return Excel::download($result['report'], $result['filename']);
    }
}
