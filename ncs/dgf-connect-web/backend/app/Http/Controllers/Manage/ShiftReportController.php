<?php

namespace App\Http\Controllers\Manage;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ShiftReport\IndexRequest;
use App\Http\Requests\Manage\ShiftReport\MonthIndexRequest;
use App\Services\Manage\ShiftReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ShiftReportController extends Controller
{
    private readonly ShiftReportService $shiftReportService;

    /**
     * @param ShiftReportService $shiftReportService
     */
    public function __construct(ShiftReportService $shiftReportService)
    {
        $this->shiftReportService = $shiftReportService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        if (empty($conditions['dates'])) {
            $conditions['dates'] = [now()->subDays(7), now()];
        }

        return ResponseHelper::successPaginated($this->shiftReportService->list($conditions, Auth::user()));
    }

    public function getMonthlyReport(MonthIndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        if (empty($conditions['month'])) {
            $conditions['month'] = now()->format('Y-m');
        }

        $results = $this->shiftReportService->listMonthly($conditions, Auth::user());

        return ResponseHelper::successPaginated($results);
    }
}
