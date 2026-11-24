<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ErrorCode;
use App\Enums\GroupKind;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\SourceTier;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use App\ValueObjects\GroupIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupApiCheckpointTest extends TestCase
{
    use RefreshDatabase;

    public function testGroupApiContractMatchesCheckpointExpectations()
    {
        $admin = $this->createAdminWithGroupPermissions();
        $workplace = Workplace::factory()->create(['name' => 'Musashino']);
        $systemGroup = $this->createSystemGroup($workplace);
        $manualGroup = $this->createManualGroup('Holiday standby pool');

        $activeMember = User::factory()->create([
            'staff_no' => 'E-0042',
        ]);
        $activeMember->workplaces()->attach($workplace->id);
        UserGroup::query()->create([
            'group_id' => $systemGroup->id,
            'user_id' => $activeMember->id,
        ]);

        $lockedMember = User::factory()->create([
            'is_disabled' => true,
            'staff_no' => 'E-0043',
        ]);
        $lockedMember->workplaces()->attach($workplace->id);
        UserGroup::query()->create([
            'group_id' => $systemGroup->id,
            'user_id' => $lockedMember->id,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/admin/groups?kind[]=' . GroupKind::WorkplaceSeniority->value . '&search=Musashino')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.data')
            ->assertJsonFragment([
                'id' => $systemGroup->id,
                'kind' => GroupKind::WorkplaceSeniority->value,
                'source_workplace_id' => $workplace->id,
                'source_prefecture' => null,
                'source_tier' => SourceTier::Veteran->value,
                'member_count' => 1,
            ])
            ->assertJsonFragment([
                'id' => $workplace->id,
                'name' => 'Musashino',
            ]);

        $this->actingAs($admin, 'api')
            ->getJson("/admin/groups/{$systemGroup->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $systemGroup->id)
            ->assertJsonPath('data.kind', GroupKind::WorkplaceSeniority->value)
            ->assertJsonPath('data.source_workplace.id', $workplace->id)
            ->assertJsonPath('data.source_workplace.name', 'Musashino')
            ->assertJsonPath('data.source_tier', SourceTier::Veteran->value)
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonCount(1, 'data.members.data')
            ->assertJsonPath('data.members.data.0.id', $activeMember->id)
            ->assertJsonPath('data.members.data.0.staff_no', 'E-0042')
            ->assertJsonPath('data.members.data.0.current_workplaces.0.id', $workplace->id)
            ->assertJsonPath('data.members.data.0.current_workplaces.0.name', 'Musashino');

        $this->actingAs($admin, 'api')
            ->postJson('/admin/groups', [
                'kind' => GroupKind::Manual->value,
                'name' => 'Weekend standby',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.kind', GroupKind::Manual->value)
            ->assertJsonPath('data.name', 'Weekend standby')
            ->assertJsonPath('data.member_count', 0);

        $matchUser = User::factory()->create();
        $matchUser->info()->update([
            'prefecture' => 'tokyo',
            'japanese_level' => 'n3',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/admin/groups', [
                'kind' => GroupKind::RuleBased->value,
                'name' => 'Tokyo N3',
                'predicate' => [
                    ['field' => 'prefecture', 'values' => ['TOKYO']],
                    ['field' => 'japanese_level', 'values' => ['n3']],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.kind', GroupKind::RuleBased->value)
            ->assertJsonPath('data.name', 'Tokyo N3')
            ->assertJsonPath('data.predicate.0.field', 'prefecture')
            ->assertJsonPath('data.predicate.0.values.0', 'tokyo')
            ->assertJsonPath('data.member_count', 1);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/groups/{$manualGroup->id}/members/{$activeMember->id}")
            ->assertStatus(200);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/groups/{$manualGroup->id}/members/{$activeMember->id}")
            ->assertStatus(200);

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/groups/{$manualGroup->id}")
            ->assertStatus(200);
    }

    public function testSystemGroupWritesAndLockedUserAttachReturn422()
    {
        $admin = $this->createAdminWithGroupPermissions();
        $workplace = Workplace::factory()->create();
        $systemGroup = $this->createSystemGroup($workplace);
        $manualGroup = $this->createManualGroup('Attach target');
        $lockedUser = User::factory()->create([
            'is_disabled' => true,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/groups/{$systemGroup->id}", [
                'name' => 'Should fail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UnprocessableEntity->value)
            ->assertJsonPath('data.group.0', 'System groups are read-only.');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/groups/{$manualGroup->id}/members/{$lockedUser->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::UnprocessableEntity->value)
            ->assertJsonPath('data.user_id.0', 'Locked users cannot be added to groups.');
    }

    private function createAdminWithGroupPermissions(): User
    {
        foreach ([PermissionName::GroupView->value, PermissionName::GroupEdit->value] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', [PermissionName::GroupView->value, PermissionName::GroupEdit->value])
                ->pluck('id')
                ->all()
        );

        $user = User::factory()->create();
        $user->addRole($role);

        return $user;
    }

    private function createSystemGroup(Workplace $workplace): Group
    {
        return Group::firstOrCreateByIdentity(
            new GroupIdentity(
                kind: GroupKind::WorkplaceSeniority,
                sourceWorkplaceId: $workplace->id,
                sourceTier: SourceTier::Veteran
            )
        );
    }

    private function createManualGroup(string $name): Group
    {
        return Group::query()->create([
            'kind' => GroupKind::Manual,
            'name' => $name,
            'identity_key' => (new GroupIdentity(kind: GroupKind::Manual, name: $name))->key(),
        ]);
    }
}
