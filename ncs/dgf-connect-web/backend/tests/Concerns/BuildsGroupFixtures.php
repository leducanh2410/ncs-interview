<?php

namespace Tests\Concerns;

use App\Enums\PermissionName;
use App\Enums\GroupKind;
use App\Enums\ShiftUserStatus;
use App\Enums\SourceTier;
use App\Models\CheckIn;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\UserInfo;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\ValueObjects\GroupIdentity;

trait BuildsGroupFixtures
{
    /**
     * @param  array<int, PermissionName|string>  $permissions
     */
    protected function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = array_map(function (PermissionName|string $permission) {
            $name = $permission instanceof PermissionName ? $permission->value : $permission;

            return Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name])->id;
        }, $permissions);

        $user->permissions()->syncWithoutDetaching($permissionIds);

        return $user;
    }

    protected function createGroup(
        GroupKind $kind,
        ?string $name = null,
        ?Workplace $workplace = null,
        ?string $prefecture = null,
        ?SourceTier $tier = null,
        ?array $predicate = null
    ): Group {
        if ($kind->isAdmin()) {
            return Group::query()->create([
                'kind' => $kind,
                'name' => $name,
                'predicate' => $predicate,
                'identity_key' => (new GroupIdentity(kind: $kind, name: $name))->key(),
            ]);
        }

        return Group::firstOrCreateByIdentity(
            new GroupIdentity(
                kind: $kind,
                sourceWorkplaceId: $workplace?->id,
                sourcePrefecture: $prefecture,
                sourceTier: $tier
            )
        );
    }

    protected function createWorkplacePosition(Workplace $workplace, string $name = 'Staff'): WorkplacePosition
    {
        return WorkplacePosition::query()->create([
            'workplace_id' => $workplace->id,
            'name' => $name,
        ]);
    }

    protected function createCompletedShift(
        User $user,
        Workplace $workplace,
        WorkplacePosition $position,
        string $date,
        string $startTime = '09:00:00',
        string $endTime = '17:00:00',
        bool $isAbsent = false
    ): ShiftUser {
        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => $isAbsent,
        ]);

        CheckIn::query()->create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => $date . ' ' . $startTime,
            'check_out_time' => $date . ' ' . $endTime,
            'work_minutes' => 480,
        ]);

        return $shiftUser;
    }

    protected function clearUserMemberships(User $user): void
    {
        UserGroup::query()->where('user_id', $user->id)->delete();
    }

    protected function updateUserInfo(User $user, array $attributes): UserInfo
    {
        $info = $user->info()->firstOrFail();
        $info->fill($attributes);
        $info->save();

        return $info->fresh();
    }
}
