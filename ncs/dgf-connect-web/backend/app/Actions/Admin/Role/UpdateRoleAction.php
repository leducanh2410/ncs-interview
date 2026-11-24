<?php

namespace App\Actions\Admin\Role;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleId;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class UpdateRoleAction
{
    public function __invoke(
        Role $role,
        string $name,
        string $displayName,
        ?string $description,
        array $permissionNames
    ): ActionResult {
        if (in_array($role->id, [RoleId::GlobalAdmin->value, RoleId::User->value])) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $permissions = Permission::query()->whereIn('name', $permissionNames)->get();
        if ($permissions->isEmpty()) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $role = DB::transaction(function () use ($role, $name, $displayName, $description, $permissions) {
            $role->update([
                'name' => $name,
                'display_name' => $displayName,
                'description' => $description,
            ]);
            $role->syncPermissions($permissions);

            return $role;
        });

        return new ActionResult(data: $role);
    }
}
