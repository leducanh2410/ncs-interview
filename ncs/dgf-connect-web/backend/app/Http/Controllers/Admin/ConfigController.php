<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Setting\UpdateRequest;
use App\Services\Admin\ConfigService;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    private readonly ConfigService $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function index(): JsonResponse
    {
        $configs = $this->configService->getAllConfigs();

        return ResponseHelper::success($configs);
    }

    public function update(UpdateRequest $request): JsonResponse
    {
        $this->configService->updateConfigs($request->validated());

        return ResponseHelper::success();
    }
}
