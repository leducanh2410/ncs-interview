<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\ResetPasswordRequest;
use App\Http\Requests\Admin\CreateAdminRequest;
use App\Http\Requests\Admin\IndexAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Models\User;
use App\Services\Admin\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use App\Http\Requests\Admin\Auth\SendResetPasswordLinkRequest;

class AdminController extends Controller
{
    private AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    public function index(IndexAdminRequest $request): JsonResponse
    {
        $admins = $this->adminService->listAdmins($request->validated());

        return ResponseHelper::successPaginated($admins);
    }

    public function show(User $admin): JsonResponse
    {
        return ResponseHelper::success($this->adminService->show($admin));
    }

    public function create(CreateAdminRequest $request): JsonResponse
    {
        $admin = $this->adminService->create($request->validated());

        return ResponseHelper::success($admin);
    }

    public function update(User $admin, UpdateAdminRequest $request): JsonResponse
    {
        $updated = $this->adminService->update($admin, $request->validated());

        return ResponseHelper::success($updated);
    }

    public function resendInvitation(User $admin): JsonResponse
    {
        $this->adminService->resendInvitation($admin);

        return ResponseHelper::success();
    }

    public function verifyInvitation(User $user): RedirectResponse
    {
        $signedUrl = URL::temporarySignedRoute(
            'admin.setup-password',
            now()->addHours(1),
            ['user' => $user->id]
        );

        $urlComponents = parse_url($signedUrl);
        parse_str($urlComponents['query'], $queryParams);

        $params = [
            'user_id' => $user->id,
            'signature' => $queryParams['signature'],
            'expires' => $queryParams['expires'],
        ];

        $frontendUrl = config('app.front_url').'/setup-password?'.http_build_query($params);

        return redirect($frontendUrl);
    }

    public function setupPassword(User $user, ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->adminService->setupPassword($user, $request->validated());

        return ResponseHelper::success($user);
    }

    public function sendPasswordResetLink(SendResetPasswordLinkRequest $request): JsonResponse
    {
        $this->adminService->sendPasswordResetLink($request->validated()['email']);

        return ResponseHelper::success();
    }

    public function requestDelete(User $admin): JsonResponse
    {
        $user = $this->adminService->requestDelete($admin);

        return ResponseHelper::success($user);
    }

    
}
