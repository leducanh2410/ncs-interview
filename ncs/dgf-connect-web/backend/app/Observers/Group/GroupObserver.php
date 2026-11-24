<?php

namespace App\Observers\Group;

use App\Actions\Admin\Group\BuildGroupDisplayName;
use App\Models\Group;

class GroupObserver
{
    public function __construct(private readonly BuildGroupDisplayName $buildGroupDisplayName) {}

    public function saving(Group $group): void
    {
        $group->display_name = ($this->buildGroupDisplayName)($group);
    }
}
