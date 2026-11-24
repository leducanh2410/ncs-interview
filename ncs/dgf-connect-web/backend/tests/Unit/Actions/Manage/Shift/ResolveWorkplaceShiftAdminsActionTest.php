<?php

namespace Tests\Unit\Actions\Manage\Shift;

use App\Actions\Manage\Shift\ResolveWorkplaceShiftAdminsAction;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveWorkplaceShiftAdminsActionTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplaceOne;

    private Workplace $workplaceTwo;

    private Role $shiftManagerRole;

    private Role $otherRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplaceOne = Workplace::create([
            'name' => 'Workplace 1',
            'address' => 'Address 1',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceTwo = Workplace::create([
            'name' => 'Workplace 2',
            'address' => 'Address 2',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $shiftPermission = Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        $otherPermission = Permission::create(['name' => 'work_history.list', 'display_name' => 'List Work History']);

        $this->shiftManagerRole = Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
        $this->shiftManagerRole->permissions()->attach($shiftPermission);

        $this->otherRole = Role::create(['name' => 'other_role', 'display_name' => 'Other Role']);
        $this->otherRole->permissions()->attach($otherPermission);

        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
    }

    public function testReturnsAdministratorsKeyedByWorkplaceForMultiWorkplaceBatch()
    {
        $userOne = User::factory()->create();
        $userOne->addRole($this->shiftManagerRole, $this->workplaceOne->id);

        $userTwo = User::factory()->create();
        $userTwo->addRole($this->shiftManagerRole, $this->workplaceTwo->id);

        $action = new ResolveWorkplaceShiftAdminsAction();
        $result = $action([$this->workplaceOne->id, $this->workplaceTwo->id]);

        $this->assertEqualsCanonicalizing([$userOne->id], $result[$this->workplaceOne->id]);
        $this->assertEqualsCanonicalizing([$userTwo->id], $result[$this->workplaceTwo->id]);
    }

    public function testExcludesUserWhoseRoleGrantsNoShiftPermission()
    {
        $user = User::factory()->create();
        $user->addRole($this->otherRole, $this->workplaceOne->id);

        $action = new ResolveWorkplaceShiftAdminsAction();
        $result = $action([$this->workplaceOne->id]);

        $this->assertArrayNotHasKey($this->workplaceOne->id, $result);
    }

    public function testExcludesUserWhoseShiftRoleHasNullTeamId()
    {
        $user = User::factory()->create();
        $user->addRole($this->shiftManagerRole, null);

        $action = new ResolveWorkplaceShiftAdminsAction();
        $result = $action([$this->workplaceOne->id]);

        $this->assertArrayNotHasKey($this->workplaceOne->id, $result);
    }

    public function testExcludesUserHoldingOnlyGlobalAdmin()
    {
        $globalAdminRole = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $shiftPermission = Permission::where('name', 'shift.list')->first();
        $globalAdminRole->permissions()->attach($shiftPermission);

        $user = User::factory()->create();
        $user->addRole($globalAdminRole, $this->workplaceOne->id);

        $action = new ResolveWorkplaceShiftAdminsAction();
        $result = $action([$this->workplaceOne->id]);

        $this->assertArrayNotHasKey($this->workplaceOne->id, $result);
    }

    public function testExcludesAdministratorScopedToADifferentWorkplace()
    {
        $user = User::factory()->create();
        $user->addRole($this->shiftManagerRole, $this->workplaceTwo->id);

        $action = new ResolveWorkplaceShiftAdminsAction();
        $result = $action([$this->workplaceOne->id]);

        $this->assertArrayNotHasKey($this->workplaceOne->id, $result);
    }

    public function testReturnsEmptyArrayForEmptyWorkplaceList()
    {
        $action = new ResolveWorkplaceShiftAdminsAction();
        $result = $action([]);

        $this->assertSame([], $result);
    }

    public function testResolvesInASingleQueryForTheWholeBatch()
    {
        $userOne = User::factory()->create();
        $userOne->addRole($this->shiftManagerRole, $this->workplaceOne->id);

        $userTwo = User::factory()->create();
        $userTwo->addRole($this->shiftManagerRole, $this->workplaceTwo->id);

        $action = new ResolveWorkplaceShiftAdminsAction();

        DB::enableQueryLog();
        $action([$this->workplaceOne->id, $this->workplaceTwo->id]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queryCount);
    }
}
