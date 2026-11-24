<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Helpers\RequestHelper;
use App\Helpers\ResponseHelper;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuthController extends Controller
{
    private const TOKEN_REMEMBER_HOURS = 720;

    private readonly AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * @throws Throwable
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $errorResponse = $this->validateLogin($request->only('login_id', 'password'), $request->input('company_code', ''), $request->input('login_type', ''));
        if ($errorResponse) {
            return $errorResponse;
        }

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

    public function authCheck(): JsonResponse
    {
        $user = Auth::user();

        return ResponseHelper::success($this->getAuthData($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->revokeToken(RequestHelper::getTokenFromRequest($request));

        return ResponseHelper::success(true);
    }

    public function updateLanguage(Request $request): JsonResponse
    {
        $request->validate([
            'lang_code' => 'required|string|in:ja,en,zh-CN,zh-TW,vi,tl,th,pt,es,id',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->lang_code = $request->input('lang_code');
        $user->save();

        return ResponseHelper::success(['lang_code' => $user->lang_code]);
    }

    private function validateLogin(array $credentials, $companyCode = '', $loginType = ''): ?JsonResponse
    {
        $companyId = null;
        if ($companyCode) {
            $company = Company::query()->where('company_code', $companyCode)->first();
            if (! $company) {
                return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
            }
            $companyId = $company->id;
        }

        $result = Auth::attempt($companyId ? array_merge($credentials, ['company_id' => $companyId]) : $credentials);
        if (! $result) {
            return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->is_disabled) {
            return ResponseHelper::unauthorized(ErrorCode::AuthUserDisabled);
        }

        if ($user->employee_status === EmployeeStatus::Retired->value) {
            return ResponseHelper::unauthorized(ErrorCode::AuthUserDisabled);
        }

        if ($user->company_id && $companyCode) {
            $company = Company::query()->find($user->company_id);
            if (! $company || $company->company_code !== $companyCode) {
                return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
            }
        } elseif ($user->company_id && ! $companyCode) {
            return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
        } elseif (! $user->company_id && $companyCode) {
            return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
        }

        // Validate login_type against user role
        if ($loginType === 'staff') {
            if (! $user->hasRole(RoleName::Staff->value) && ! $user->hasRole(RoleName::StaffManager->value)) {
                return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
            }
        } elseif ($loginType === 'admin') {
            if (! $user->hasRole(RoleName::Admin->value) && ! $user->hasRole(RoleName::Doctor->value)) {
                return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
            }
        } elseif ($loginType === 'super_admin') {
            if (! $user->hasRole(RoleName::SuperAdmin->value)) {
                return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
            }
        }

        return null;
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
