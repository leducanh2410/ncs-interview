<?php

namespace Tests\Unit\Actions\Common\CheckIn;

use App\Actions\Common\CheckIn\AssertShiftWorkplaceAccessAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class AssertShiftWorkplaceAccessActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private AssertShiftWorkplaceAccessAction $action;

    private Workplace $workplace;

    private ShiftUser $shiftUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new AssertShiftWorkplaceAccessAction();

        Permission::firstOrCreate(
            ['name' => PermissionName::ShiftCheckIn->value],
            ['display_name' => 'Shift Check In']
        );

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
        $employee = User::factory()->create();
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $employee->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        $this->shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testAllowsManagerScopedToTheShiftWorkplace(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $this->workplace->id);

        $this->assertNull($this->invokeForCheckIn($manager, $this->shiftUser->id));
    }

    public function testRejectsManagerScopedToAnotherWorkplace(): void
    {
        $other = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $other->id);

        $this->assertSame(
            ErrorCode::CheckInWorkplaceOutOfScope,
            $this->invokeForCheckIn($manager, $this->shiftUser->id)
        );
    }

    public function testRejectsMissingShiftUser(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $this->workplace->id);

        $this->assertSame(ErrorCode::CheckInWorkplaceOutOfScope, $this->invokeForCheckIn($manager, 999999));
    }

    public function testRejectsMissingShiftUserForDirectWorkplaceScopedPermission(): void
    {
        $manager = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftCheckIn->value)->firstOrFail();
        $manager->givePermission($permission, $this->workplace->id);

        $this->assertSame(ErrorCode::CheckInWorkplaceOutOfScope, $this->invokeForCheckIn($manager, 999999));
    }

    public function testAllowsMissingShiftUserThroughForGlobalAccess(): void
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $admin->addRole($role);

        $this->assertNull($this->invokeForCheckIn($admin, 999999));
    }

    public function testAllowsMissingShiftUserThroughForDirectGlobalPermission(): void
    {
        $manager = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftCheckIn->value)->firstOrFail();
        $manager->givePermission($permission, null);

        $this->assertNull($this->invokeForCheckIn($manager, 999999));
    }

    public function testRejectsNullActor(): void
    {
        $this->assertSame(ErrorCode::Forbidden, $this->invokeForCheckIn(null, $this->shiftUser->id));
    }

    public function testAllowsDirectGlobalPermission(): void
    {
        $manager = User::factory()->create();
        $permission = Permission::where('name', PermissionName::ShiftCheckIn->value)->firstOrFail();
        $manager->givePermission($permission, null);

        $this->assertNull($this->invokeForCheckIn($manager, $this->shiftUser->id));
    }

    public function testAllowsGlobalAdminAssignedToAnotherWorkplace(): void
    {
        $other = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $admin->addRole($role, $other->id);

        $this->assertNull($this->invokeForCheckIn($admin, $this->shiftUser->id));
    }

    public function testAcceptsBoundShiftUserModel(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftCheckIn, $this->workplace->id);

        $this->assertNull($this->invokeForCheckIn($manager, $this->shiftUser));
    }

    public function testUsesCallerProvidedOutOfScopeError(): void
    {
        $other = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($manager, PermissionName::ShiftUpdateSalary, $other->id);

        $this->assertSame(
            ErrorCode::Forbidden,
            ($this->action)(
                $manager,
                $this->shiftUser->id,
                PermissionName::ShiftUpdateSalary->value,
                ErrorCode::Forbidden
            )
        );
    }

    private function invokeForCheckIn(mixed $actor, ShiftUser|int $shiftUser): ?ErrorCode
    {
        return ($this->action)($actor, $shiftUser, PermissionName::ShiftCheckIn->value);
    }
}
