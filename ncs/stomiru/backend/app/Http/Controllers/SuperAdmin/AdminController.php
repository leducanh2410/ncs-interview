<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SuperAdmin\AdminService;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    private AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    public function delete(User $user): JsonResponse
    {
        $this->adminService->delete($user);

        return ResponseHelper::success();
    }
}
