<?php

namespace App\Actions\Admin\Group;

use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\ValidationException;

class DetachManualGroupMemberAction
{
    public function __construct(private readonly SyncUserGroupChatParticipantsAction $syncChat) {}

    public function __invoke(Group $group, User $user): void
    {
        if ($group->kind !== GroupKind::Manual) {
            throw ValidationException::withMessages([
                'group' => ['Only manual groups can be edited directly.'],
            ]);
        }

        UserGroup::query()->where('group_id', $group->id)->where('user_id', $user->id)->delete();

        ($this->syncChat)($group->id);
    }
}
