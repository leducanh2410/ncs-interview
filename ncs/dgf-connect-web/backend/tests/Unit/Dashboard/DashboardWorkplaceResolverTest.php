<?php

namespace Tests\Unit\Dashboard;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWorkplaceResolverTest extends TestCase
{
    use RefreshDatabase;

    private Permission $shiftListPermission;

    private Permission $workHistoryListPermission;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2, 3, 4] as $id) {
            Workplace::create([
                'id' => $id,
                'name' => "Workplace {$id}",
                'address' => "Address {$id}",
                'latitude' => 35.6895,
                'longitude' => 139.6917,
            ]);
        }

        $this->shiftListPermission = Permission::create([
            'name' => 'shift.list',
            'display_name' => 'List Shifts',
        ]);
        $this->workHistoryListPermission = Permission::create([
            'name' => 'work_history.list',
            'display_name' => 'List Work History',
        ]);

        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
        Role::create(['name' => 'history_viewer', 'display_name' => 'History Viewer']);
    }

    public function testDemandReturnsNullForGlobalAdmin(): void
    {
        $user = $this->makeGlobalAdmin();
        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertNull($resolver->demand());
        $this->assertNull($resolver->reliability());
    }

    public function testReliabilityFallsBackToShiftListWhenWorkHistoryIsGlobal(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'shift_manager', 'shift.list', [1, 2]);
        $this->grantPermissionGlobally($user, 'history_viewer', 'work_history.list');

        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertEqualsCanonicalizing([1, 2], $resolver->demand());
        $this->assertEqualsCanonicalizing([1, 2], $resolver->reliability());
    }

    public function testReliabilityFallsBackToWorkHistoryWhenShiftListIsGlobal(): void
    {
        $user = User::factory()->create();
        $this->grantPermissionGlobally($user, 'shift_manager', 'shift.list');
        $this->grantPermission($user, 'history_viewer', 'work_history.list', [2, 3]);

        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertNull($resolver->demand());
        $this->assertEqualsCanonicalizing([2, 3], $resolver->reliability());
    }

    public function testReliabilityIntersectsTwoNonNullSets(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'shift_manager', 'shift.list', [1, 2, 3]);
        $this->grantPermission($user, 'history_viewer', 'work_history.list', [2, 3, 4]);

        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertEqualsCanonicalizing([1, 2, 3], $resolver->demand());
        $this->assertEqualsCanonicalizing([2, 3], $resolver->reliability());
    }

    public function testReliabilityCollapsesToEmptyWhenSetsDoNotOverlap(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'shift_manager', 'shift.list', [1, 2]);
        $this->grantPermission($user, 'history_viewer', 'work_history.list', [3, 4]);

        $resolver = new DashboardWorkplaceResolver($user);

        $reliability = $resolver->reliability();
        $this->assertIsArray($reliability);
        $this->assertSame([], $reliability);
        $this->assertTrue($resolver->isEmpty($reliability));
    }

    public function testIntersectWithRequestedDropsOutOfScopeIds(): void
    {
        $user = $this->makeGlobalAdmin();
        $resolver = new DashboardWorkplaceResolver($user);

        $result = $resolver->intersectWithRequested([1, 2, 3], [2, 4, 99]);

        $this->assertEqualsCanonicalizing([2], $result);
    }

    public function testIntersectWithRequestedReturnsSetWhenRequestedIsNull(): void
    {
        $user = $this->makeGlobalAdmin();
        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertEqualsCanonicalizing([1, 2, 3], $resolver->intersectWithRequested([1, 2, 3], null));
    }

    public function testIntersectWithRequestedReturnsRequestedWhenSetIsNull(): void
    {
        $user = $this->makeGlobalAdmin();
        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertEqualsCanonicalizing([1, 2], $resolver->intersectWithRequested(null, [1, 2]));
    }

    public function testIntersectWithRequestedReturnsNullWhenBothNull(): void
    {
        $user = $this->makeGlobalAdmin();
        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertNull($resolver->intersectWithRequested(null, null));
    }

    public function testIsEmptyTreatsNullAsNotEmpty(): void
    {
        $user = $this->makeGlobalAdmin();
        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertFalse($resolver->isEmpty(null));
        $this->assertTrue($resolver->isEmpty([]));
        $this->assertFalse($resolver->isEmpty([1]));
    }

    public function testDemandReturnsEmptyArrayForUserWithoutAnyAccess(): void
    {
        $user = User::factory()->create();
        $resolver = new DashboardWorkplaceResolver($user);

        $this->assertSame([], $resolver->demand());
        $this->assertSame([], $resolver->reliability());
        $this->assertTrue($resolver->isEmpty($resolver->demand()));
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

    private function grantPermissionGlobally(User $user, string $roleName, string $permissionName): void
    {
        $role = Role::where('name', $roleName)->first();
        $permission = Permission::where('name', $permissionName)->first();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user->addRole($role, null);
    }
}
