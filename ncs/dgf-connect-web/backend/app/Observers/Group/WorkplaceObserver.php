<?php

namespace App\Observers\Group;

use App\Actions\Admin\Group\BuildGroupDisplayName;
use App\Actions\Admin\Group\EnsureSystemGroupsForWorkplaceAction;
use App\Models\Group;
use App\Models\Workplace;

class WorkplaceObserver
{
    public function __construct(
        private readonly EnsureSystemGroupsForWorkplaceAction $ensureSystemGroupsForWorkplace,
        private readonly BuildGroupDisplayName $buildGroupDisplayName
    ) {}

    public function created(Workplace $workplace): void
    {
        ($this->ensureSystemGroupsForWorkplace)($workplace);
    }

    public function updated(Workplace $workplace): void
    {
        if (!$workplace->wasChanged('name')) {
            return;
        }

        Group::query()
            ->where('source_workplace_id', $workplace->id)
            ->each(function (Group $group) use ($workplace) {
                $group->setRelation('workplace', $workplace);
                $group->display_name = ($this->buildGroupDisplayName)($group);
                $group->saveQuietly();
            });
    }
}
