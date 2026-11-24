<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\GroupKind;
use App\Enums\PermissionName;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class UserListGroupFilterTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testGroupsFilterUsesAndSemanticsAndExcludesLockedUsers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::UserList]);
        $groupA = $this->createGroup(GroupKind::Manual, 'Tokyo group');
        $groupB = $this->createGroup(GroupKind::Manual, 'Night group');

        $matchingUser = User::factory()->create();
        UserGroup::query()->create([
            'group_id' => $groupA->id,
            'user_id' => $matchingUser->id,
        ]);
        UserGroup::query()->create([
            'group_id' => $groupB->id,
            'user_id' => $matchingUser->id,
        ]);

        $singleGroupUser = User::factory()->create();
        UserGroup::query()->create([
            'group_id' => $groupA->id,
            'user_id' => $singleGroupUser->id,
        ]);

        $lockedMatchingUser = User::factory()->create([
            'is_disabled' => true,
        ]);
        UserGroup::query()->create([
            'group_id' => $groupA->id,
            'user_id' => $lockedMatchingUser->id,
        ]);
        UserGroup::query()->create([
            'group_id' => $groupB->id,
            'user_id' => $lockedMatchingUser->id,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/users?groups[]={$groupA->id}&groups[]={$groupB->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $matchingUser->id);
    }
}
