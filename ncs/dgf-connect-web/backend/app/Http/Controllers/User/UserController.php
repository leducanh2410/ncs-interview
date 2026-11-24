<?php

namespace App\Http\Controllers\User;

use App\Actions\Common\ChangePasswordAction;
use App\Actions\User\Profile\RequestAccountDeletionAction;
use App\Actions\User\Profile\UpdateUserInfoAction;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateUserInfoRequest;
use App\Http\Requests\User\SearchPaperRequest;
use App\Http\Requests\User\UpdateSettingsRequest;
use App\Http\Requests\User\UploadPaperRequest;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private readonly UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /****************************************
     * UserInfo                             *
     ****************************************/
    public function getUserInfo(): JsonResponse
    {
        return ResponseHelper::success($this->userService->getUserInfo());
    }

    /****************************************
     * Papers                               *
     ****************************************/

    public function getPapers(SearchPaperRequest $request): JsonResponse
    {
        $data = $this->userService->getUserPapers($request->validated());

        return ResponseHelper::success($data);
    }

    public function uploadPapers(UploadPaperRequest $request): JsonResponse
    {
        $result = $this->userService->uploadUserPapers($request->validated());
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    /****************************************
     * Settings                             *
     ****************************************/

    public function updateSettings(UpdateSettingsRequest $request): JsonResponse
    {
        $result = $this->userService->updateSettings($request->validated());
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    /****************************************
     * Password                             *
     ****************************************/

    public function updatePassword(Request $request, ChangePasswordAction $action): JsonResponse
    {
        $request->validate([
            'current_password' => 'current_password',
            'password' => ['required', 'min:6', 'confirmed'],
        ]);

        return $action($request->input('password'))->toResponse();
    }

    public function updateUserInfo(UpdateUserInfoRequest $request, UpdateUserInfoAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse();
    }

    public function requestAccountDeletion(RequestAccountDeletionAction $action): JsonResponse
    {
        return $action()->toResponse();
    }
}
