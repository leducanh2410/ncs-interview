<?php

namespace App\Http\Controllers\Staff;

use App\Enums\RoleName;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\Auth\ResetPasswordRequest;
use App\Http\Requests\Staff\Profile\UpdateProfileRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Admin\EmployeeService;
use App\Services\AuthService;
use App\Services\Staff\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    private const TOKEN_REMEMBER_HOURS = 720;

    private ProfileService $profileService;

    private EmployeeService $employeeService;

    private readonly AuthService $authService;

    public function __construct(ProfileService $profileService, EmployeeService $employeeService, AuthService $authService)
    {
        $this->profileService = $profileService;
        $this->employeeService = $employeeService;
        $this->authService = $authService;
    }

    public function setFirstPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = Auth::user();
        $user = $this->employeeService->setupPassword($user, $request->validated());

        $data = DB::transaction(function () use ($request) {
            $data = $this->getAuthData();

            $accessToken = $this->authService->createToken(
                $data['user']->id,
                $request->input('remember', false) ? self::TOKEN_REMEMBER_HOURS : 1
            );
            $data['token'] = $accessToken->token;

            return $data;
        });

        return ResponseHelper::success($data);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $profile = $this->profileService->update($user, $request->validated());

        return ResponseHelper::success($profile);
    }

    public function show(): JsonResponse
    {
        $user = Auth::user();
        $profile = $this->profileService->show($user);

        return ResponseHelper::success($profile);
    }

    private function getAuthData(?User $user = null): ?array
    {
        $user = $user ?? Auth::user();

        if (! $user) {
            return null;
        }

        // assign company_code
        if ($user->company_id) {
            $company = Company::query()->find($user->company_id);
            $user->company_code = $company->company_code;
        }

        if (($user->hasRole(RoleName::StaffManager->value) || $user->hasRole(RoleName::Staff->value)) && $user->temp_password) {
            $user->need_password_change = true;
        }

        $data = [
            'user' => $user,
            'roles' => $user->getRolesSimple(),
        ];

        return $data;
    }
}
