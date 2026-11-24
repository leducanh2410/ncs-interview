<?php

namespace App\Actions\Admin\Role;

use App\Actions\ActionResult;
use App\Models\Role;

class GetRoleDetailAction
{
    public function __invoke(Role $role): ActionResult
    {
        $role->load('permissions');

        return new ActionResult(data: $role);
    }
}
