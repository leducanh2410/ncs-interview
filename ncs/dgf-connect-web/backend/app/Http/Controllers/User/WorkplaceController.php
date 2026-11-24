<?php

namespace App\Http\Controllers\User;

use App\Actions\User\Workplace\RegisterWorkplacesAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\RegisterWorkplacesRequest;
use App\Models\Workplace;
use App\Services\User\WorkplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class WorkplaceController extends Controller
{
    private readonly WorkplaceService $workplaceService;

    public function __construct(WorkplaceService $workplaceService)
    {
        $this->workplaceService = $workplaceService;
    }

    public function getWorkplaces(): JsonResponse
    {
        $result = $this->workplaceService->getWorkplaceList();

        return ResponseHelper::success($result);
    }

    public function registerWorkplaces(
        RegisterWorkplacesRequest $request,
        RegisterWorkplacesAction $action
    ): JsonResponse {
        $params = $request->validated();

        return $action(Auth::id(), $params['workplace_ids'])->toResponse();
    }

    public function getInstructions(Workplace $workplace): JsonResponse
    {
        $result = $this->workplaceService->getWorkplaceInstructions($workplace);
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }
}
