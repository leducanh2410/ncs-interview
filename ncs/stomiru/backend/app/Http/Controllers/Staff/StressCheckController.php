<?php

namespace App\Http\Controllers\Staff;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StressCheck\IndexRequest;
use App\Models\StressCheckPeriod;
use App\Services\Staff\StressCheckService;
use Illuminate\Http\JsonResponse;

class StressCheckController extends Controller
{
    private StressCheckService $stressCheckService;

    public function __construct(StressCheckService $stressCheckService)
    {
        $this->stressCheckService = $stressCheckService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $stressCheckPeriods = $this->stressCheckService->stressCheckHistory($request->validated());

        return ResponseHelper::successPaginated($stressCheckPeriods);
    }

    public function getActiveStressCheck(): JsonResponse
    {
        $stressCheckPeriod = $this->stressCheckService->getActiveStressCheck();

        return ResponseHelper::success($stressCheckPeriod);
    }

    public function getExam(StressCheckPeriod $stressCheckPeriod): JsonResponse
    {
        $stressCheckPeriod = $this->stressCheckService->getExam($stressCheckPeriod);

        return ResponseHelper::success($stressCheckPeriod);
    }
}
