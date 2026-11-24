<?php

namespace App\Actions\Admin\Group;

use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\ValidationException;

class AttachManualGroupMemberAction
{
    public function __construct(private readonly SyncUserGroupChatParticipantsAction $syncChat) {}

    public function __invoke(Group $group, User $user): void
    {
        if ($group->kind !== GroupKind::Manual) {
            throw ValidationException::withMessages([
                'group' => ['Only manual groups can be edited directly.'],
            ]);
        }

        if ($user->is_disabled) {
            throw ValidationException::withMessages([
                'user_id' => ['Locked users cannot be added to groups.'],
            ]);
        }

        $exists = UserGroup::query()->where('group_id', $group->id)->where('user_id', $user->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'user_id' => ['The user is already a member of this group.'],
            ]);
        }

        $now = now();

        UserGroup::query()->create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        ($this->syncChat)($group->id);
    }
}
