<?php

namespace App\Actions\Admin\Group;

use App\Actions\Chat\UserGroup\SyncUserGroupChatParticipantsAction;
use App\Enums\GroupKind;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\ValidationException;

class BatchAttachManualGroupMembersAction
{
    public function __construct(private readonly SyncUserGroupChatParticipantsAction $syncChat) {}

    public function __invoke(Group $group, array $userIds): void
    {
        if ($group->kind !== GroupKind::Manual) {
            throw ValidationException::withMessages([
                'group' => ['Only manual groups can be edited directly.'],
            ]);
        }

        $existingUserIds = UserGroup::query()
            ->where('group_id', $group->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $newUserIds = array_values(array_diff($userIds, $existingUserIds));

        if (empty($newUserIds)) {
            return;
        }

        $users = User::query()->whereIn('id', $newUserIds)->get();

        $now = now();

        foreach ($users as $user) {
            if ($user->is_disabled) {
                throw ValidationException::withMessages([
                    'user_ids' => [__('Locked users cannot be added to groups.')],
                ]);
            }

            UserGroup::query()->create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        ($this->syncChat)($group->id);
    }
}
