<?php

namespace App\Actions\Admin\Role;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class CreateRoleAction
{
    public function __invoke(
        string $name,
        string $displayName,
        ?string $description,
        array $permissionNames
    ): ActionResult {
        $permissions = Permission::query()->whereIn('name', $permissionNames)->get();
        if ($permissions->isEmpty()) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $role = DB::transaction(function () use ($name, $displayName, $description, $permissions) {
            $role = Role::query()->create([
                'name' => $name,
                'display_name' => $displayName,
                'description' => $description,
            ]);
            $role->givePermissions($permissions);

            return $role;
        });

        return new ActionResult(data: $role);
    }
}
