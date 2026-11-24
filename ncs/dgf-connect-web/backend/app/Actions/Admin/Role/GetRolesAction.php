<?php

namespace App\Actions\Admin\Role;

use App\Actions\ActionResult;
use App\Models\Role;

class GetRolesAction
{
    public function __invoke(array $conditions): ActionResult
    {
        return new ActionResult(data: Role::all());
    }
}
