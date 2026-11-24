<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Role\CreateRoleAction;
use App\Actions\Admin\Role\DeleteRoleAction;
use App\Actions\Admin\Role\GetRoleDetailAction;
use App\Actions\Admin\Role\GetRolesAction;
use App\Actions\Admin\Role\UpdateRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Role\CreateUpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request, GetRolesAction $action): JsonResponse
    {
        return $action($request->all())->toResponse();
    }

    public function store(CreateUpdateRoleRequest $request, CreateRoleAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action($data['name'], $data['display_name'], $data['description'], $data['permissions'])->toResponse();
    }

    public function show(Role $role, GetRoleDetailAction $action): JsonResponse
    {
        return $action($role)->toResponse();
    }

    public function update(Role $role, CreateUpdateRoleRequest $request, UpdateRoleAction $action): JsonResponse
    {
        $data = $request->validated();

        return $action(
            $role,
            $data['name'],
            $data['display_name'],
            $data['description'],
            $data['permissions']
        )->toResponse();
    }

    public function destroy(Role $role, DeleteRoleAction $action): JsonResponse
    {
        return $action($role)->toResponse();
    }
}
