<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Log\IndexRequest;
use App\Models\Company;
use App\Services\SuperAdmin\LogService;
use Illuminate\Http\JsonResponse;

class LogController extends Controller
{
    private LogService $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    public function index(IndexRequest $request, Company $company): JsonResponse
    {
        $logs = $this->logService->listLogs($request->validated(), $company);

        return ResponseHelper::successPaginated($logs);
    }
}
