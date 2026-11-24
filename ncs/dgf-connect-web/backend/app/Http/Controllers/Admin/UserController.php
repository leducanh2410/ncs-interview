<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\User\ApplyOcrFieldsAction;
use App\Actions\Admin\User\ApprovePapersAction;
use App\Actions\Admin\User\AssignRolesAction;
use App\Actions\Admin\User\CreateEmployeeAction;
use App\Actions\Admin\User\CreateManagerAction;
use App\Actions\Admin\User\DeletePapersAction;
use App\Actions\Admin\User\ExtractPapersOcrAction;
use App\Actions\Admin\User\GetUpcomingShiftsAction;
use App\Actions\Admin\User\MovePapersAction;
use App\Actions\Admin\User\RemoveRoleAction;
use App\Actions\Admin\User\ReopenEmptiedDocumentStepsAction;
use App\Actions\Admin\User\ReopenPendingDocumentStepsAction;
use App\Actions\Admin\User\RequestDocumentCompletionAction;
use App\Actions\Admin\User\ResetPasswordAction;
use App\Actions\Admin\User\RestoreDeletedUserAction;
use App\Actions\Admin\User\RestorePapersAction;
use App\Actions\Admin\User\SyncFromCrossStaffAction;
use App\Actions\Admin\User\SyncToCrossStaffAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\AddToWorkplacesRequest;
use App\Http\Requests\Admin\User\ApplyOcrFieldsRequest;
use App\Http\Requests\Admin\User\ApprovePapersRequest;
use App\Http\Requests\Admin\User\AssignRoleRequest;
use App\Http\Requests\Admin\User\DeletePapersRequest;
use App\Http\Requests\Admin\User\DisableUserRequest;
use App\Http\Requests\Admin\User\EnableUserRequest;
use App\Http\Requests\Admin\User\ExtractPapersOcrRequest;
use App\Http\Requests\Admin\User\IndexRequest;
use App\Http\Requests\Admin\User\MovePapersRequest;
use App\Http\Requests\Admin\User\RemoveRoleRequest;
use App\Http\Requests\Admin\User\RestorePapersRequest;
use App\Http\Requests\Admin\User\UpdateConfigRequest;
use App\Http\Requests\Admin\User\UpdateRequest;
use App\Http\Requests\Admin\User\UploadImageRequest;
use App\Http\Requests\Auth\StoreRegisterRequest;
use App\Models\User;
use App\Models\Workplace;
use App\Rules\UniquePassportNumber;
use App\Rules\UniqueResidenceNumber;
use App\Services\Admin\UserService;
use App\Services\Admin\WorkplaceService;
use App\Services\AuthService;
use App\Services\External\CrossStaffApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private readonly UserService $userService;

    private readonly WorkplaceService $workplaceService;

    /**
     * @param UserService $userService
     * @param WorkplaceService $workplaceService
     */
    public function __construct(UserService $userService, WorkplaceService $workplaceService)
    {
        $this->userService = $userService;
        $this->workplaceService = $workplaceService;
    }

    public function index(IndexRequest $request): JsonResponse
    {
        $conditions = $request->validated();
        if (!isset($conditions['is_disabled'])) {
            $conditions['is_disabled'] = false;
        }

        $canViewConfigs = $request->user()->hasPermission(PermissionName::UserConfigList->value);
        if (!$canViewConfigs) {
            unset($conditions['custom_config']);
        }

        $users = $this->userService->listUsers($conditions, $canViewConfigs);

        return ResponseHelper::successPaginated($users);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->userService->getUserDetail($id);

        return ResponseHelper::success($user);
    }

    public function update(int $id, UpdateRequest $request): JsonResponse
    {
        $result = $this->userService->updateUserDetail($id, $request);
        if (!$result['result']) {
            return ResponseHelper::badRequest($result['error']);
        }

        return ResponseHelper::success($result['user']);
    }

    public function getImages(int $id): JsonResponse
    {
        return ResponseHelper::success($this->userService->getUserImages($id));
    }

    public function uploadImage(int $id, UploadImageRequest $request): JsonResponse
    {
        $path = $this->userService->uploadUserImage($id, $request);

        return ResponseHelper::success($path);
    }

    public function deleteImage(int $userId, int $id): JsonResponse
    {
        $this->userService->deleteUserImage($userId, $id);

        return ResponseHelper::success();
    }

    public function getDevices(int $id): JsonResponse
    {
        return ResponseHelper::success($this->userService->getUserDevices($id));
    }

    public function deleteDevice(int $userId, int $id): JsonResponse
    {
        $this->userService->deleteUserDevice($userId, $id);

        return ResponseHelper::success();
    }

    public function getUserWorkplaces(int $id): JsonResponse
    {
        return ResponseHelper::success($this->userService->getUserWorkplaces($id));
    }

    public function addUserIntoWorkplaces(int $id, AddToWorkplacesRequest $request): JsonResponse
    {
        $error = $this->userService->addUserIntoWorkplaces($id, $request->validated()['workplace_ids']);
        if ($error) {
            return ResponseHelper::badRequest($error);
        }

        return ResponseHelper::success();
    }

    public function deleteUserWorkplace(User $user, Workplace $workplace): JsonResponse
    {
        $result = $this->workplaceService->detachUser($workplace, $user);
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400);
        }

        return ResponseHelper::success();
    }

    public function disableUser(int $userId, DisableUserRequest $request): JsonResponse
    {
        $params = $request->validated();
        $result = $this->userService->disableUser($userId, $params);
        if (!$result['result']) {
            return ResponseHelper::badRequest($result['error']);
        }

        return ResponseHelper::success();
    }

    public function enableUser(int $userId, EnableUserRequest $request): JsonResponse
    {
        $params = $request->validated();
        $result = $this->userService->enableUser($userId, $params);
        if (!$result['result']) {
            return ResponseHelper::badRequest($result['error']);
        }

        return ResponseHelper::success();
    }

    public function getUpcomingShifts(User $user, Request $request, GetUpcomingShiftsAction $action): JsonResponse
    {
        $withinDays = $request->query('within_days');

        return ResponseHelper::success($action($user, $withinDays === null ? null : (int) $withinDays));
    }

    public function getLockHistories(User $user): JsonResponse
    {
        $lockHistories = $this->userService->getLockHistories($user);

        return ResponseHelper::success($lockHistories);
    }

    public function getRoles(int $userId): JsonResponse
    {
        $user = User::query()->findOrFail($userId);

        return ResponseHelper::success($user->getRolesSimple());
    }

    public function assignRoles(int $userId, AssignRoleRequest $request, AssignRolesAction $action): JsonResponse
    {
        $validated = $request->validated();
        $result = $action($userId, $validated['role_name'], $validated['workplace_id'] ?? null);

        if ($result->getError()) {
            return $result->toResponse();
        }

        return ResponseHelper::success($result->getData(), $result->getStatus() ?? 200);
    }

    public function removeRole(int $userId, RemoveRoleRequest $request, RemoveRoleAction $action): JsonResponse
    {
        $validated = $request->validated();
        $result = $action($userId, $validated['role_name'], $validated['workplace_id'] ?? null);

        if ($result->getError()) {
            return $result->toResponse();
        }

        return ResponseHelper::success($result->getData(), $result->getStatus() ?? 200);
    }

    public function getPapers(User $user): JsonResponse
    {
        return ResponseHelper::success($this->userService->getPapers($user));
    }

    public function approvePapers(User $user, ApprovePapersRequest $request, ApprovePapersAction $action): JsonResponse
    {
        return $action($user, $request->input('items', []), $request->input('rejected_note'))->toResponse();
    }

    public function requestPaperCompletion(User $user, RequestDocumentCompletionAction $action): JsonResponse
    {
        return $action($user)->toResponse();
    }

    public function reopenPaperSteps(User $user, ReopenPendingDocumentStepsAction $action): JsonResponse
    {
        return $action($user)->toResponse();
    }

    public function reopenEmptiedPaperSteps(User $user, ReopenEmptiedDocumentStepsAction $action): JsonResponse
    {
        return $action($user)->toResponse();
    }

    public function deletePapers(User $user, DeletePapersRequest $request, DeletePapersAction $action): JsonResponse
    {
        return $action($user, $request->input('ids'))->toResponse();
    }

    public function restorePapers(User $user, RestorePapersRequest $request, RestorePapersAction $action): JsonResponse
    {
        return $action($user, $request->input('ids'))->toResponse();
    }

    public function movePapers(User $user, MovePapersRequest $request, MovePapersAction $action): JsonResponse
    {
        return $action($user, $request->input('ids'), $request->input('folder'))->toResponse();
    }

    public function extractPapersOcr(
        User $user,
        ExtractPapersOcrRequest $request,
        ExtractPapersOcrAction $action
    ): JsonResponse {
        return $action($user, $request->validated('types'), $request->boolean('force'))->toResponse();
    }

    public function applyPapersOcr(
        User $user,
        ApplyOcrFieldsRequest $request,
        ApplyOcrFieldsAction $action
    ): JsonResponse {
        return $action($user, (int) $request->validated('ocr_result_id'), $request->validated('fields'))->toResponse();
    }

    public function createManager(Request $request, CreateManagerAction $createManagerAction): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_kana' => ['required', 'string', 'max:255'],
            'last_name_kana' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(RoleName::values())],
        ]);

        $role = $data['role'];
        unset($data['role']);

        $result = $createManagerAction($data, $role);
        if ($result->getError()) {
            return $result->toResponse();
        }

        return ResponseHelper::success($result->getData(), $result->getStatus() ?? 200);
    }

    public function syncToCrossStaff(User $user, SyncToCrossStaffAction $action): JsonResponse
    {
        return $action($user)->toResponse();
    }

    public function syncFromCrossStaff(User $user, SyncFromCrossStaffAction $action): JsonResponse
    {
        return $action($user)->toResponse();
    }

    public function getUserConfigs(User $user): JsonResponse
    {
        $configs = $this->userService->getUserConfigs($user->id);

        return ResponseHelper::success($configs);
    }

    public function updateUserConfigs(User $user, UpdateConfigRequest $request): JsonResponse
    {
        $this->userService->updateUserConfigs($user->id, $request->validated());

        return ResponseHelper::success();
    }

    public function restoreDeletedUser(User $user, RestoreDeletedUserAction $action): JsonResponse
    {
        $result = $action($user);
        if ($result->getError()) {
            return $result->toResponse();
        }

        return ResponseHelper::success($result->getData(), $result->getStatus() ?? 200);
    }

    public function resetPassword(User $user, Request $request, ResetPasswordAction $action): JsonResponse
    {
        $validated = Validator::validate($request->all(), [
            'password' => ['required', 'string', 'confirmed'],
        ]);

        return $action($user, $validated['password'], $request->user())->toResponse();
    }

    public function initEmployee(Request $request): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        $email = $data['email'];

        // Try to find user in CrossStaff
        $crossStaffUser = null;
        try {
            $crossStaffApiService = new CrossStaffApiService();
            $crossStaffUser = $crossStaffApiService->searchUser($email);
        } catch (\Throwable $e) {
            // Don't block init if CrossStaff fails
            report($e);
        }

        // Create cache entry
        $key = 'emp_' . Str::random();
        $cacheData = [
            'email' => $email,
            'is_verified' => true,
            'user' => $crossStaffUser,
        ];
        Cache::put($key, $cacheData, now()->addDay());

        return ResponseHelper::success([
            'key' => $key,
            'email' => $email,
            'user' => $crossStaffUser,
        ]);
    }

    public function setEmployeePassword(Request $request, AuthService $authService): JsonResponse
    {
        $data = Validator::validate($request->all(), [
            'key' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ]);

        $result = $authService->storeTempUser($data);

        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $result['data'] ?? null);
        }

        return ResponseHelper::success();
    }

    public function registerEmployee(Request $request, CreateEmployeeAction $action): JsonResponse
    {
        $rules = array_merge((new StoreRegisterRequest())->rules(), [
            'phone' => ['nullable', 'string', 'max:255'],
            'plain_password' => ['required', 'string'],
        ]);

        try {
            $validated = Validator::validate($request->all(), $rules);
        } catch (ValidationException $exception) {
            $residenceNumberValidator = Validator::make($request->all(), [
                'residence_number_1' => ['nullable', new UniqueResidenceNumber()],
            ]);
            if ($residenceNumberValidator->fails()) {
                return ResponseHelper::unprocessableEntity(ErrorCode::RegisterResidenceNumberAlreadyExists);
            }

            $passportNumberValidator = Validator::make($request->all(), [
                'passport_number' => ['nullable', new UniquePassportNumber()],
            ]);
            if ($passportNumberValidator->fails()) {
                return ResponseHelper::unprocessableEntity(ErrorCode::RegisterPassportNumberAlreadyExists);
            }

            throw $exception;
        }

        $plainPassword = $validated['plain_password'];
        unset($validated['plain_password']);

        return $action($validated['key'], $validated, $plainPassword)->toResponse();
    }
}
