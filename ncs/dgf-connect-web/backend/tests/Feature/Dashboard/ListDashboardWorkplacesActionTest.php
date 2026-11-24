<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsAggregateOnlyResponse;
use Tests\TestCase;

class ListDashboardWorkplacesActionTest extends TestCase
{
    use AssertsAggregateOnlyResponse;
    use RefreshDatabase;

    private Permission $shiftListPermission;

    private Permission $workHistoryListPermission;

    protected function setUp(): void
    {
        parent::setUp();

        Workplace::create([
            'id' => 1,
            'name' => 'Yokohama A',
            'address' => 'A',
            'latitude' => 35.6,
            'longitude' => 139.6,
        ]);
        Workplace::create([
            'id' => 2,
            'name' => 'Kawasaki Pack',
            'address' => 'B',
            'latitude' => 35.6,
            'longitude' => 139.6,
        ]);
        Workplace::create(['id' => 3, 'name' => 'Osaka C', 'address' => 'C', 'latitude' => 34.6, 'longitude' => 135.5]);

        $this->shiftListPermission = Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        $this->workHistoryListPermission = Permission::create([
            'name' => 'work_history.list',
            'display_name' => 'Work History',
        ]);

        $globalAdminRole = Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        $globalAdminRole->permissions()->syncWithoutDetaching([$this->shiftListPermission->id]);

        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
        Role::create(['name' => 'history_viewer', 'display_name' => 'History Viewer']);
    }

    public function testGlobalAdminSeesAllWorkplacesWithGlobalFlag(): void
    {
        $user = $this->makeGlobalAdmin();

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/workplaces');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['demand_is_global']);
        $this->assertTrue($data['reliability_is_global']);
        $this->assertCount(3, $data['demand_workplaces']);
        $this->assertCount(3, $data['reliability_workplaces']);
        $this->assertNoUserIdentifyingFields($data);
    }

    public function testAdminWithMixedPermissionsGetsCorrectIntersection(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'shift_manager', 'shift.list', [1, 2]);
        $this->grantPermission($user, 'history_viewer', 'work_history.list', [2, 3]);

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/workplaces');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertFalse($data['demand_is_global']);
        $this->assertFalse($data['reliability_is_global']);
        $this->assertCount(2, $data['demand_workplaces']);
        $this->assertCount(1, $data['reliability_workplaces']);
        $this->assertEquals(2, $data['reliability_workplaces'][0]['id']);
        $this->assertNoUserIdentifyingFields($data);
    }

    public function testReturnsForbiddenWhenUserLacksShiftList(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/workplaces');

        $response->assertForbidden();
    }

    public function testReturnsUnauthorizedForUnauthenticatedRequest(): void
    {
        $response = $this->getJson('/admin/dashboard/workplaces');

        $response->assertUnauthorized();
    }

    public function testExcludesTestWorkplacesByDefault(): void
    {
        Workplace::create([
            'id' => 100,
            'name' => 'Test Hub',
            'address' => 'T',
            'latitude' => 35.6,
            'longitude' => 139.6,
            'is_test' => true,
        ]);
        config()->set('app.show_test_workplaces', false);

        $user = $this->makeGlobalAdmin();

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/workplaces');

        $response->assertOk();
        $demandIds = array_column($response->json('data')['demand_workplaces'], 'id');

        $this->assertNotContains(100, $demandIds);
        $this->assertContains(1, $demandIds);
    }

    public function testIncludesTestWorkplacesWhenEnabled(): void
    {
        Workplace::create([
            'id' => 100,
            'name' => 'Test Hub',
            'address' => 'T',
            'latitude' => 35.6,
            'longitude' => 139.6,
            'is_test' => true,
        ]);
        config()->set('app.show_test_workplaces', true);

        $user = $this->makeGlobalAdmin();

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/workplaces');

        $response->assertOk();
        $demandIds = array_column($response->json('data')['demand_workplaces'], 'id');

        $this->assertContains(100, $demandIds);
    }

    public function testIncludesTestWorkplacesForTestAdminEvenWhenDisabled(): void
    {
        Workplace::create([
            'id' => 100,
            'name' => 'Test Hub',
            'address' => 'T',
            'latitude' => 35.6,
            'longitude' => 139.6,
            'is_test' => true,
        ]);
        config()->set('app.show_test_workplaces', false);

        $user = User::factory()->create(['is_test' => true]);
        $user->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first());

        $response = $this->actingAs($user, 'api')->getJson('/admin/dashboard/workplaces');

        $response->assertOk();
        $demandIds = array_column($response->json('data')['demand_workplaces'], 'id');

        $this->assertContains(100, $demandIds);
    }

    private function makeGlobalAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $user->addRole($role);

        return $user;
    }

    private function grantPermission(User $user, string $roleName, string $permissionName, array $workplaceIds): void
    {
        $role = Role::where('name', $roleName)->first();
        $permission = Permission::where('name', $permissionName)->first();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        foreach ($workplaceIds as $workplaceId) {
            $user->addRole($role, $workplaceId);
        }
    }
}
