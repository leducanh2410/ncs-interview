<?php

namespace App\Actions\Admin\Role;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleId;
use App\Models\Role;

class DeleteRoleAction
{
    public function __invoke(Role $role): ActionResult
    {
        if (in_array($role->id, [RoleId::GlobalAdmin->value, RoleId::Admin->value, RoleId::User->value])) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $role->delete();

        return new ActionResult(data: true);
    }
}
