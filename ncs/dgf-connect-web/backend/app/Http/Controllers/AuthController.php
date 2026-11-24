<?php

namespace App\Http\Controllers;

use App\Actions\Common\Exam\GetPendingRequiredExamsForAuthAction;
use App\Actions\Common\Poll\EvaluatePendingVotesFlagAction;
use App\Actions\Manage\Auth\RequestPasswordResetAction;
use App\Actions\Manage\Auth\ResetPasswordAction;
use App\Actions\User\Auth\RegisterNewUserAction;
use App\Actions\User\Auth\RequestPasswordResetUserAction;
use App\Actions\User\Auth\ResetPasswordUserAction;
use App\Actions\User\Profile\RestoreDeletedAccountAction;
use App\Enums\ErrorCode;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Helpers\RequestHelper;
use App\Helpers\ResponseHelper;
use App\Http\Requests\Auth\LoginAdminRequest;
use App\Http\Requests\Auth\LoginAdminVerifyRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterPasswordRequest;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\RequestResetPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\RestoreAccountRequest;
use App\Http\Requests\Auth\StoreRegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Rules\UniquePassportNumber;
use App\Rules\UniqueResidenceNumber;
use App\Services\AuthService;
use App\Services\DocumentReadConfirmationService;
use App\Services\User\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RobThree\Auth\TwoFactorAuthException;

class AuthController extends Controller
{
    private const TOKEN_REMEMBER_HOURS = 1440;

    private readonly AuthService $authService;

    private readonly NotificationService $notificationService;

    private readonly DocumentReadConfirmationService $confirmationService;

    private readonly EvaluatePendingVotesFlagAction $evaluatePendingVotesFlagAction;

    private readonly GetPendingRequiredExamsForAuthAction $getPendingRequiredExamsForAuthAction;

    public function __construct(
        AuthService $authService,
        NotificationService $notificationService,
        DocumentReadConfirmationService $confirmationService,
        EvaluatePendingVotesFlagAction $evaluatePendingVotesFlagAction,
        GetPendingRequiredExamsForAuthAction $getPendingRequiredExamsForAuthAction
    ) {
        $this->authService = $authService;
        $this->notificationService = $notificationService;
        $this->confirmationService = $confirmationService;
        $this->evaluatePendingVotesFlagAction = $evaluatePendingVotesFlagAction;
        $this->getPendingRequiredExamsForAuthAction = $getPendingRequiredExamsForAuthAction;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $errorResponse = $this->validateLogin($request->only('email', 'password'), false);
        if ($errorResponse) {
            return $errorResponse;
        }

        $data = DB::transaction(function () use ($request) {
            /** @var User $user */
            $user = Auth::user();

            // Persist the client's language preference before building the
            // auth response: getAuthData() resolves pending_exams/pending_votes
            // using the user's saved locale, so a first-time login without a
            // saved `lang` would otherwise serialize them in APP_LOCALE.
            $lang = $request->input('lang');
            $currentLang = Arr::get($user->settings, 'lang');
            if ($lang && !$currentLang) {
                $this->authService->updateUserLang($user, $lang);
            }

            $data = $this->getAuthData($user);
            $accessToken = $this->authService->createToken(
                $user->id,
                $request->input('remember', false) ? self::TOKEN_REMEMBER_HOURS : 1
            );
            $data['token'] = $accessToken->token;
            $this->authService->storeUserDevice($user->id, $request->validated());

            return $data;
        });

        return ResponseHelper::success($data);
    }

    /**
     * @throws TwoFactorAuthException
     */
    public function loginAdmin(LoginAdminRequest $request): JsonResponse
    {
        $errorResponse = $this->validateLogin($request->only('email', 'password'), true);
        if ($errorResponse) {
            return $errorResponse;
        }

        /** @var User $user */
        $user = Auth::user();

        $tfaEnabled = config('auth.tfa.enabled') && !$this->authService->shouldSkipTfa($user->email);
        if ($tfaEnabled) {
            $tfaData = $this->authService->createTfaSession($user, $request->input('remember', false));
            $responseData = ['tfa' => $tfaData];
        } else {
            $responseData = $this->getAuthData($user);
            $accessToken = $this->authService->createToken(
                $user->id,
                $request->input('remember', false) ? self::TOKEN_REMEMBER_HOURS : 1
            );
            $responseData['token'] = $accessToken->token;
            $this->authService->storeUserDevice($user->id, $request->validated());
        }

        return ResponseHelper::success($responseData);
    }

    public function loginAdminTfa(LoginAdminVerifyRequest $request): JsonResponse
    {
        $result = $this->authService->verifyTfa($request->input('key'), $request->input('code'));
        if (isset($result['error'])) {
            return ResponseHelper::unauthorized($result['error']);
        }

        $responseData = $this->getAuthData($result['user']);
        $accessToken = $this->authService->createToken(
            $result['user']->id,
            $result['remember'] ? self::TOKEN_REMEMBER_HOURS : 1
        );
        $responseData['token'] = $accessToken->token;
        $this->authService->storeUserDevice($result['user']->id, $request->validated());

        return ResponseHelper::success($responseData);
    }

    public function authCheck(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        if (!$user->hasRole(RoleName::User->value)) {
            return ResponseHelper::forbidden();
        }

        return ResponseHelper::success($this->getAuthData($user));
    }

    public function authCheckManager(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        if (!$user->hasPermission(PermissionName::ManagerAuth->value)) {
            return ResponseHelper::forbidden();
        }

        return ResponseHelper::success($this->getAuthData($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->revokeToken(RequestHelper::getTokenFromRequest($request));

        return ResponseHelper::success(true);
    }

    public function requestPasswordReset(
        RequestResetPasswordRequest $request,
        RequestPasswordResetAction $action
    ): JsonResponse {
        return $action($request->input('turnstile_token'), $request->input('email'), $request->ip())->toResponse();
    }

    public function passwordReset(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        return $action($request->validated())->toResponse();
    }

    public function requestPasswordResetUser(Request $request, RequestPasswordResetUserAction $action): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'max:255', 'email'],
        ]);

        return $action($request->input('email'))->toResponse();
    }

    public function passwordResetUser(Request $request, ResetPasswordUserAction $action): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'otp' => ['required', 'string'],
            'password' => ['required', 'min:6', 'confirmed'],
        ]);

        return $action($validated)->toResponse();
    }

    public function requestRegisterOtp(RequestOtpRequest $request): JsonResponse
    {
        $result = $this->authService->sendRegisterOtp($request->validated());

        return !isset($result['error']) ? ResponseHelper::success($result) : ResponseHelper::badRequest();
    }

    public function restoreAccount(RestoreAccountRequest $request, RestoreDeletedAccountAction $action): JsonResponse
    {
        return $action($request->input('key'), $request->input('otp'))->toResponse();
    }

    public function verifyRegisterOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->authService->verifyRegisterOtp($request->validated());
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function storeTempUser(RegisterPasswordRequest $request): JsonResponse
    {
        $result = $this->authService->storeTempUser($request->validated());
        $data = $result['data'] ?? null;
        if ($result['error']) {
            return ResponseHelper::error($result['error'], $result['status'] ?? 400, $data);
        }

        return ResponseHelper::success($data);
    }

    public function registerUser(Request $request, RegisterNewUserAction $action): JsonResponse
    {
        $rules = (new StoreRegisterRequest())->rules();
        try {
            $validated = Validator::validate($request->all(), $rules);
        } catch (ValidationException $exception) {
            $validated = $request->only(array_keys($rules));
            logger('Register user validation error', [
                'request' => $request->all(),
                'errors' => $exception->validator->errors()->toArray(),
            ]);
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
        }

        $result = $action($validated['key'], $validated);

        return $result->toResponse();
    }

    private function validateLogin(array $credentials, bool $isAdmin): ?JsonResponse
    {
        $result = Auth::attempt($credentials);
        if (!$result) {
            return ResponseHelper::unauthorized(ErrorCode::AuthInvalidCredentials);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->is_disabled) {
            return ResponseHelper::unauthorized(ErrorCode::AuthUserDisabled);
        }

        if ($isAdmin && !$user->hasPermission(PermissionName::ManagerAuth->value)) {
            return ResponseHelper::forbidden();
        }
        if (!$isAdmin && !$user->hasRole(RoleName::User->value)) {
            return ResponseHelper::forbidden();
        }

        return null;
    }

    private function getAuthData(?User $user = null): ?array
    {
        $user = $user ?? Auth::user();

        if (!$user) {
            return null;
        }

        // Login itself isn't behind the `auth:api` + SetLocale middleware
        // group (no token yet), so the locale must be resolved explicitly
        // here too, otherwise pending_exams/pending_votes below would use
        // the app's default locale instead of the user's saved preference.
        $lang = $user->settings['lang'] ?? null;
        if ($lang && in_array($lang, Locale::values())) {
            App::setLocale($lang);
        }

        $data = [
            'user' => $user,
            'roles' => $user->getRolesSimple(),
        ];

        if ($user->hasPermission(PermissionName::ManagerAuth->value)) {
            $data['permissions'] = $user->allPermissions(['name'])->pluck('name');
        } else {
            $data['master'] = $this->authService->getUserMasterData($user->id);
            $data['unread_notifications'] = $this->notificationService->countUnread();
            $data['pending_documents'] = $this->confirmationService->getPendingForLoginResponse($user);
            $data['pending_votes'] = $this->evaluatePendingVotesFlagAction->getForAuthResponse($user);
            $data['pending_exams'] = $this->getPendingRequiredExamsForAuthAction->__invoke($user);
            $user->append([
                'student_card_expiry_alert',
                'residence_card_expiry_alert',
                'passport_expiry_alert',
                'face_registered',
            ]);
        }

        return $data;
    }
}
