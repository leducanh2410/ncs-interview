<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ErrorCode;
use App\Enums\GroupKind;
use App\Enums\JapaneseLevel;
use App\Enums\PermissionName;
use App\Enums\SourceTier;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class GroupControllerTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testIndexFiltersByKindAndSearchAndCountsOnlyActiveMembers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView]);
        $musashino = Workplace::factory()->create(['name' => 'Musashino']);
        $shibuya = Workplace::factory()->create(['name' => 'Shibuya']);

        $newcomerGroup = $this->createGroup(
            GroupKind::WorkplaceSeniority,
            workplace: $musashino,
            tier: SourceTier::Newcomer
        );
        $regularGroup = $this->createGroup(
            GroupKind::WorkplaceSeniority,
            workplace: $musashino,
            tier: SourceTier::Regular
        );
        $veteranGroup = $this->createGroup(
            GroupKind::WorkplaceSeniority,
            workplace: $musashino,
            tier: SourceTier::Veteran
        );
        $this->createGroup(GroupKind::WorkplaceSeniority, workplace: $shibuya, tier: SourceTier::Veteran);

        $activeMember = User::factory()->create(['staff_no' => 'E-0042']);
        $activeMember->workplaces()->attach($musashino->id);
        UserGroup::query()->create([
            'group_id' => $veteranGroup->id,
            'user_id' => $activeMember->id,
        ]);

        $lockedMember = User::factory()->create([
            'is_disabled' => true,
            'staff_no' => 'E-0043',
        ]);
        $lockedMember->workplaces()->attach($musashino->id);
        UserGroup::query()->create([
            'group_id' => $veteranGroup->id,
            'user_id' => $lockedMember->id,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?kind[]=' . GroupKind::WorkplaceSeniority->value . '&search=Musashino')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 3)
            ->assertJsonFragment([
                'id' => $newcomerGroup->id,
                'kind' => GroupKind::WorkplaceSeniority->value,
                'member_count' => 0,
            ])
            ->assertJsonFragment([
                'id' => $regularGroup->id,
                'kind' => GroupKind::WorkplaceSeniority->value,
                'member_count' => 0,
            ])
            ->assertJsonFragment([
                'id' => $veteranGroup->id,
                'kind' => GroupKind::WorkplaceSeniority->value,
                'source_workplace_id' => $musashino->id,
                'source_tier' => SourceTier::Veteran->value,
                'member_count' => 1,
            ])
            ->assertJsonFragment([
                'id' => $musashino->id,
                'name' => 'Musashino',
            ]);
    }

    public function testIndexSearchByNameMatchesManualAndRuleBasedGroups(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView]);

        $manualGroup = $this->createGroup(GroupKind::Manual, 'Alpha Team');
        $ruleBasedGroup = $this->createGroup(GroupKind::RuleBased, 'Beta Squad');
        $otherManualGroup = $this->createGroup(GroupKind::Manual, 'Gamma Crew');

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?search=Alpha')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $manualGroup->id);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?search=Beta')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $ruleBasedGroup->id);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?search=Team')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $manualGroup->id);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?search=ALPHA')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $manualGroup->id);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?search=squad')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $ruleBasedGroup->id);
    }

    public function testShowReturnsMembersAndExcludesLockedUsers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView]);
        $workplace = Workplace::factory()->create(['name' => 'Musashino']);
        $group = $this->createGroup(GroupKind::WorkplaceSeniority, workplace: $workplace, tier: SourceTier::Veteran);

        $activeMember = User::factory()->create(['staff_no' => 'E-0042']);
        $activeMember->workplaces()->attach($workplace->id);
        UserGroup::query()->create([
            'group_id' => $group->id,
            'user_id' => $activeMember->id,
        ]);

        $lockedMember = User::factory()->create([
            'is_disabled' => true,
            'staff_no' => 'E-0043',
        ]);
        $lockedMember->workplaces()->attach($workplace->id);
        UserGroup::query()->create([
            'group_id' => $group->id,
            'user_id' => $lockedMember->id,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/groups/{$group->id}?members_per_page=5")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $group->id)
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonCount(1, 'data.members.data')
            ->assertJsonPath('data.members.data.0.id', $activeMember->id)
            ->assertJsonPath('data.members.data.0.staff_no', 'E-0042')
            ->assertJsonPath('data.members.data.0.current_workplaces.0.id', $workplace->id)
            ->assertJsonPath('data.members.data.0.current_workplaces.0.name', 'Musashino');
    }

    public function testManualCrudAndMemberAttachDetachFlow(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $member = User::factory()->create();

        $createResponse = $this->actingAs($admin, 'api')->postJson('/admin/groups', [
            'kind' => GroupKind::Manual->value,
            'name' => 'Weekend standby',
        ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('data.kind', GroupKind::Manual->value)
            ->assertJsonPath('data.name', 'Weekend standby')
            ->assertJsonPath('data.member_count', 0);

        $groupId = $createResponse->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/groups/{$groupId}", ['name' => 'Weekend standby updated'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Weekend standby updated');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/groups/{$groupId}/members/{$member->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $member->id,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/groups/{$groupId}")
            ->assertStatus(200)
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonCount(1, 'data.members.data');

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/groups/{$groupId}/members/{$member->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $groupId,
            'user_id' => $member->id,
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/groups/{$groupId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('groups', ['id' => $groupId]);
    }

    public function testRuleBasedCrudRecomputesMemberships(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);

        $tokyoUser = User::factory()->create();
        $this->updateUserInfo($tokyoUser, [
            'prefecture' => 'tokyo',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $osakaUser = User::factory()->create();
        $this->updateUserInfo($osakaUser, [
            'prefecture' => 'osaka',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $createResponse = $this->actingAs($admin, 'api')->postJson('/admin/groups', [
            'kind' => GroupKind::RuleBased->value,
            'name' => 'Tokyo N3',
            'predicate' => [
                ['field' => 'prefecture', 'values' => ['TOKYO']],
                ['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]],
            ],
        ]);

        $createResponse
            ->assertStatus(201)
            ->assertJsonPath('data.kind', GroupKind::RuleBased->value)
            ->assertJsonPath('data.predicate.0.field', 'prefecture')
            ->assertJsonPath('data.predicate.0.values.0', 'tokyo')
            ->assertJsonPath('data.member_count', 1);

        $groupId = $createResponse->json('data.id');

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $tokyoUser->id,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/groups/{$groupId}", [
                'name' => 'Osaka N3',
                'predicate' => [
                    ['field' => 'prefecture', 'values' => ['osaka']],
                    ['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Osaka N3')
            ->assertJsonPath('data.member_count', 1);

        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $groupId,
            'user_id' => $tokyoUser->id,
        ]);
        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $osakaUser->id,
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/groups/{$groupId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('groups', ['id' => $groupId]);
    }

    public function testRuleBasedMultiValuePredicateMatchesUsersInAnyValue(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);

        $tokyoUser = User::factory()->create();
        $this->updateUserInfo($tokyoUser, [
            'prefecture' => 'tokyo',
        ]);

        $osakaUser = User::factory()->create();
        $this->updateUserInfo($osakaUser, [
            'prefecture' => 'osaka',
        ]);

        $kyotoUser = User::factory()->create();
        $this->updateUserInfo($kyotoUser, [
            'prefecture' => 'kyoto',
        ]);

        $createResponse = $this->actingAs($admin, 'api')->postJson('/admin/groups', [
            'kind' => GroupKind::RuleBased->value,
            'name' => 'Kanto Kansai',
            'predicate' => [['field' => 'prefecture', 'values' => ['tokyo', 'osaka']]],
        ]);

        $createResponse->assertStatus(201)->assertJsonPath('data.member_count', 2);

        $groupId = $createResponse->json('data.id');

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $tokyoUser->id,
        ]);
        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $osakaUser->id,
        ]);
        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $groupId,
            'user_id' => $kyotoUser->id,
        ]);
    }

    public function testRuleBasedPrefectureMatchesJapaneseKanjiInUserInfo(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);

        // Simulates japan-postal-code returning kanji values
        $tokyoKanjiUser = User::factory()->create();
        $this->updateUserInfo($tokyoKanjiUser, [
            'prefecture' => '東京都',
        ]);

        $osakaKanjiUser = User::factory()->create();
        $this->updateUserInfo($osakaKanjiUser, [
            'prefecture' => '大阪府',
        ]);

        $saitamaUser = User::factory()->create();
        $this->updateUserInfo($saitamaUser, [
            'prefecture' => '埼玉県',
        ]);

        // Predicate uses English romanized values
        $createResponse = $this->actingAs($admin, 'api')->postJson('/admin/groups', [
            'kind' => GroupKind::RuleBased->value,
            'name' => 'Kanto',
            'predicate' => [['field' => 'prefecture', 'values' => ['tokyo', 'saitama']]],
        ]);

        $createResponse->assertStatus(201)->assertJsonPath('data.member_count', 2);

        $groupId = $createResponse->json('data.id');

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $tokyoKanjiUser->id,
        ]);
        $this->assertDatabaseHas('user_groups', [
            'group_id' => $groupId,
            'user_id' => $saitamaUser->id,
        ]);
        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $groupId,
            'user_id' => $osakaKanjiUser->id,
        ]);
    }

    public function testSystemGroupWritesReturn422(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupEdit]);
        $workplace = Workplace::factory()->create();
        $group = $this->createGroup(GroupKind::WorkplaceCurrent, workplace: $workplace);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/groups/{$group->id}", ['name' => 'Blocked'])
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UnprocessableEntity->value)
            ->assertJsonPath('data.group.0', 'System groups are read-only.');

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/groups/{$group->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UnprocessableEntity->value)
            ->assertJsonPath('data.group.0', 'System groups are read-only.');
    }

    public function testPermissionsAreEnforcedForReadAndWriteEndpoints(): void
    {
        $group = $this->createGroup(GroupKind::Manual, 'Shared name');
        $viewer = $this->createUserWithPermissions([PermissionName::GroupView]);
        $noPermissionsUser = User::factory()->create();

        $this->actingAs($noPermissionsUser, 'api')->getJson('/admin/groups')->assertStatus(403);

        $this->actingAs($viewer, 'api')
            ->getJson("/admin/groups/{$group->id}")
            ->assertStatus(200);

        $this->actingAs($viewer, 'api')
            ->postJson('/admin/groups', [
                'kind' => GroupKind::Manual->value,
                'name' => 'Should fail',
            ])
            ->assertStatus(403);
    }

    public function testBatchAttachMembersAddsMultipleUsersToManualGroup(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();
        $member3 = User::factory()->create();

        $group = $this->createGroup(GroupKind::Manual, 'Batch Test');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/groups/{$group->id}/members", [
                'user_ids' => [$member1->id, $member2->id],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $group->id,
            'user_id' => $member1->id,
        ]);

        $this->assertDatabaseHas('user_groups', [
            'group_id' => $group->id,
            'user_id' => $member2->id,
        ]);

        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $group->id,
            'user_id' => $member3->id,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/groups/{$group->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.member_count', 2);
    }

    public function testBatchAttachMembersSkipsAlreadyExistingMembers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $group = $this->createGroup(GroupKind::Manual, 'Batch Skip Test');

        UserGroup::query()->create([
            'group_id' => $group->id,
            'user_id' => $member1->id,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/groups/{$group->id}/members", [
                'user_ids' => [$member1->id, $member2->id],
            ])
            ->assertStatus(200);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/groups/{$group->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.member_count', 2);
    }

    public function testBatchAttachMembersRejectsLockedUsers(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupEdit]);
        $lockedMember = User::factory()->create(['is_disabled' => true]);
        $activeMember = User::factory()->create();

        $group = $this->createGroup(GroupKind::Manual, 'Batch Lock Test');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/groups/{$group->id}/members", [
                'user_ids' => [$lockedMember->id, $activeMember->id],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('user_groups', [
            'group_id' => $group->id,
            'user_id' => $activeMember->id,
        ]);
    }

    public function testListUserGroupsSuccess(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $user = User::factory()->create();
        $group = $this->createGroup(GroupKind::Manual, 'Team Alpha');
        UserGroup::query()->create(['group_id' => $group->id, 'user_id' => $user->id]);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/groups")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($group->id, $response->json('data.0.id'));
    }

    public function testListUserGroupsReturnsEmptyArrayWhenUserHasNoGroups(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $user = User::factory()->create();

        $response = $this->actingAs($admin, 'api')
            ->getJson("/admin/users/{$user->id}/groups")
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
    }

    public function testListUserGroupsForbiddenWithoutPermission(): void
    {
        $user = User::factory()->create();
        $noPermUser = User::factory()->create();

        $this->actingAs($noPermUser, 'api')
            ->getJson("/admin/users/{$user->id}/groups")
            ->assertStatus(403);
    }

    public function testListUserGroupsUnauthorized(): void
    {
        $user = User::factory()->create();

        $this->getJson("/admin/users/{$user->id}/groups")->assertStatus(401);
    }

    public function testStoreManualGroupDuplicateNameReturns422(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $this->createGroup(GroupKind::Manual, 'Existing Group');

        $this->actingAs($admin, 'api')
            ->postJson('/admin/groups', [
                'kind' => GroupKind::Manual->value,
                'name' => 'Existing Group',
            ])
            ->assertStatus(422);
    }

    public function testStoreRuleBasedGroupWithInvalidPredicateFieldReturns422(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);

        $this->actingAs($admin, 'api')
            ->postJson('/admin/groups', [
                'kind' => GroupKind::RuleBased->value,
                'name' => 'Bad Field Group',
                'predicate' => [['field' => 'invalid_field', 'values' => ['somevalue']]],
            ])
            ->assertStatus(422);
    }

    public function testStoreRuleBasedGroupTooBroadReturns422(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);

        // admin + 3 users with nationality='japanese' = 4 total active.
        // Predicate matches 3 of 4 (75%) which exceeds the 70% coverage cap.
        foreach (range(1, 3) as $_) {
            $this->updateUserInfo(User::factory()->create(), ['nationality' => 'japanese']);
        }

        $this->actingAs($admin, 'api')
            ->postJson('/admin/groups', [
                'kind' => GroupKind::RuleBased->value,
                'name' => 'Too Broad Group',
                'predicate' => [['field' => 'nationality', 'values' => ['japanese']]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.predicate.0', fn(string $msg) => str_contains($msg, '70%'));
    }

    public function testUpdateGroupWithNoFieldsReturns422(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $group = $this->createGroup(GroupKind::Manual, 'Update Target');

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/groups/{$group->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('data.group.0', 'At least one field must be provided.');
    }

    public function testUpdatePredicateOnManualGroupReturns422(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::GroupView, PermissionName::GroupEdit]);
        $group = $this->createGroup(GroupKind::Manual, 'Manual Only');

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/groups/{$group->id}", [
                'predicate' => [['field' => 'nationality', 'values' => ['japanese']]],
            ])
            ->assertStatus(422);
    }
}
