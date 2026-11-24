<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Workplace\CreateUpdateShiftAction;
use App\Actions\Admin\Workplace\ReorderPositionAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Workplace\AddUsersRequest;
use App\Http\Requests\Admin\Workplace\IndexRequest;
use App\Http\Requests\Admin\Workplace\ReorderPositionRequest;
use App\Http\Requests\Admin\Workplace\StoreImageRequest;
use App\Http\Requests\Admin\Workplace\StoreInstructionRequest;
use App\Http\Requests\Admin\Workplace\StorePositionRequest;
use App\Http\Requests\Admin\Workplace\StoreRequest;
use App\Http\Requests\Admin\Workplace\StoreShiftRequest;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceImage;
use App\Models\WorkplaceInstruction;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Admin\WorkplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkplaceController extends Controller
{
    private readonly WorkplaceService $workplaceService;

    public function __construct(WorkplaceService $workplaceService)
    {
        $this->workplaceService = $workplaceService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $workplaces = $this->workplaceService->listWorkplaces($request->validated());

        return ResponseHelper::successPaginated($workplaces);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->store($request->validated()));
    }

    public function show(Workplace $workplace): JsonResponse
    {
        return ResponseHelper::success($workplace);
    }

    public function update(Workplace $workplace, StoreRequest $request): JsonResponse
    {
        $error = $this->workplaceService->update($workplace, $request->validated());
        if ($error) {
            return ResponseHelper::badRequest($error);
        }

        return ResponseHelper::success($workplace->fresh());
    }

    public function destroy(Workplace $workplace): JsonResponse
    {
        $result = $this->workplaceService->destroy($workplace);
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success();
    }

    /****************************************
     * Image                                *
     ****************************************/

    public function listImages(Workplace $workplace): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->listImages($workplace));
    }

    public function uploadImages(Workplace $workplace, StoreImageRequest $request): JsonResponse
    {
        $isStoredSuccess = $this->workplaceService->uploadImages($workplace, $request->validated());

        return $isStoredSuccess ? ResponseHelper::success() : ResponseHelper::unprocessableEntity();
    }

    public function deleteImage(Workplace $workplace, WorkplaceImage $image): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->deleteImage($image));
    }

    /****************************************
     * Position                             *
     ****************************************/

    public function listPositions(Workplace $workplace): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->listPositions($workplace));
    }

    public function storePosition(Workplace $workplace, StorePositionRequest $request): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->storePosition($workplace, $request->validated()));
    }

    public function updatePosition(
        Workplace $workplace,
        WorkplacePosition $position,
        StorePositionRequest $request
    ): JsonResponse {
        return ResponseHelper::success($this->workplaceService->updatePosition($position, $request->validated()));
    }

    public function deletePosition(Workplace $workplace, WorkplacePosition $position): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->deletePosition($position));
    }

    public function reorderPositions(
        Workplace $workplace,
        ReorderPositionRequest $request,
        ReorderPositionAction $action
    ): JsonResponse {
        $validated = $request->validated();

        return $action($workplace, $validated)->toResponse();
    }

    /****************************************
     * Shift                                *
     ****************************************/

    public function listShifts(Workplace $workplace): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->listShifts($workplace));
    }

    public function storeShift(
        Workplace $workplace,
        StoreShiftRequest $request,
        CreateUpdateShiftAction $action
    ): JsonResponse {
        return $action($workplace, null, $request->validated())->toResponse();
    }

    public function updateShift(
        Workplace $workplace,
        WorkplaceShift $shift,
        StoreShiftRequest $request,
        CreateUpdateShiftAction $action
    ): JsonResponse {
        return $action($workplace, $shift, $request->validated())->toResponse();
    }

    public function deleteShift(Workplace $workplace, WorkplaceShift $shift): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->deleteShift($shift));
    }

    /****************************************
     * Instruction                          *
     ****************************************/

    public function listInstructions(Workplace $workplace): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->listInstructions($workplace));
    }

    public function storeInstruction(Workplace $workplace, StoreInstructionRequest $request): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->storeInstruction($workplace, $request->validated()));
    }

    public function updateInstruction(
        Workplace $workplace,
        WorkplaceInstruction $instruction,
        StoreInstructionRequest $request
    ): JsonResponse {
        return ResponseHelper::success($this->workplaceService->updateInstruction($instruction, $request->validated()));
    }

    public function deleteInstruction(Workplace $workplace, WorkplaceInstruction $instruction): JsonResponse
    {
        return ResponseHelper::success($this->workplaceService->deleteInstruction($instruction));
    }

    /****************************************
     * User                                 *
     ****************************************/

    public function listUsers(Workplace $workplace, Request $request): JsonResponse
    {
        return ResponseHelper::successPaginated(
            $this->workplaceService->getListUsers($workplace, $request->input('page_size'))
        );
    }

    public function addUsers(Workplace $workplace, AddUsersRequest $request): JsonResponse
    {
        $error = $this->workplaceService->attachUsers($workplace, $request->validated());
        if ($error) {
            return ResponseHelper::badRequest($error);
        }

        return ResponseHelper::success();
    }

    public function detachUser(Workplace $workplace, User $user): JsonResponse
    {
        $result = $this->workplaceService->detachUser($workplace, $user);
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success();
    }
}
