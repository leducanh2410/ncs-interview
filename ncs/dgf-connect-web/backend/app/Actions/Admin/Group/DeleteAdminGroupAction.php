<?php

namespace App\Actions\Admin\Group;

use App\Exceptions\SystemGroupReadonlyException;
use App\Models\Group;

class DeleteAdminGroupAction
{
    public function __invoke(Group $group): void
    {
        if ($group->isSystem()) {
            throw new SystemGroupReadonlyException();
        }

        $group->delete();
    }
}
