<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\SubmitApplicationAccountRequest;
use App\Services\ApplicationAccountService;
use Illuminate\Http\JsonResponse;

class ApplicationAccountController extends Controller
{
    private ApplicationAccountService $applicationAccountService;

    public function __construct(ApplicationAccountService $applicationAccountService)
    {
        $this->applicationAccountService = $applicationAccountService;
    }

    public function submitApplicationAccount(SubmitApplicationAccountRequest $request): JsonResponse
    {
        $company = $this->applicationAccountService->submitApplicationAccount($request->validated());

        return ResponseHelper::success($company);
    }
}
