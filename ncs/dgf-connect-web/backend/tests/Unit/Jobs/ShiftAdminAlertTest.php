<?php

namespace Tests\Unit\Jobs;

use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Jobs\ShiftAdminAlert;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftAdminAlertTest extends TestCase
{
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private WorkplacePosition $position;

    private User $creator;

    private Role $shiftManagerRole;

    protected function setUp(): void
    {
        parent::setUp();

        // 08:00 today.
        Carbon::setTestNow('2026-06-22 08:00:00');

        $this->creator = User::factory()->create();
        $this->workplace = Workplace::create([
            'name' => 'Alert WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->otherWorkplace = Workplace::create([
            'name' => 'Other WP',
            'address' => 'Addr 2',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);

        $shiftPermission = Permission::create(['name' => 'shift.list', 'display_name' => 'List Shifts']);
        $this->shiftManagerRole = Role::create(['name' => 'shift_manager', 'display_name' => 'Shift Manager']);
        $this->shiftManagerRole->permissions()->attach($shiftPermission);

        Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testEmitsOneUnconfirmedAlertPerScopedAdministratorWhenAssignmentIsUnconfirmed(): void
    {
        // Shift at 09:00 -> 60 min out, inside the default 120-min unconfirmed window.
        $shift = $this->createShift('09:00:00', '13:00:00');
        $this->createApprovedAssignment($shift);
        $admin = $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value,
            'user_id' => $admin->id,
        ]);
        $this->assertNotNull($shift->fresh()->unconfirmed_alerted_at);
    }

    public function testEmitsExactlyOneNotificationPerAdministratorForThreeUnconfirmedEmployees(): void
    {
        $shift = $this->createShift('09:00:00', '13:00:00');
        $employees = collect([
            $this->createApprovedAssignment($shift),
            $this->createApprovedAssignment($shift),
            $this->createApprovedAssignment($shift),
        ]);
        $adminOne = $this->registerAdmin($this->workplace);
        $adminTwo = $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertSame(
            2,
            Notification::query()->where('type', NotificationType::SHIFT_UNCONFIRMED_ALERT->value)->count()
        );

        $notification = Notification::query()
            ->where('type', NotificationType::SHIFT_UNCONFIRMED_ALERT->value)
            ->where('user_id', $adminOne->id)
            ->firstOrFail();
        $this->assertStringContainsString('3', $notification->content);
        $this->assertEqualsCanonicalizing($employees->pluck('user_id')->toArray(), $notification->data['user_ids']);

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value,
            'user_id' => $adminTwo->id,
        ]);
    }

    public function testEmitsNothingWhenEveryAssignmentIsConfirmed(): void
    {
        $shift = $this->createShift('09:00:00', '13:00:00');
        $this->createApprovedAssignment($shift, ['confirmed_at' => now()]);
        $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', ['type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value]);
        $this->assertNull($shift->fresh()->unconfirmed_alerted_at);
    }

    public function testEmitsNothingASecondTimeOnceStamped(): void
    {
        $shift = $this->createShift('09:00:00', '13:00:00');
        $this->createApprovedAssignment($shift);
        $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();
        $stampedAt = $shift->fresh()->unconfirmed_alerted_at;
        Notification::query()->delete();

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', ['type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value]);
        $this->assertEquals($stampedAt, $shift->fresh()->unconfirmed_alerted_at);
    }

    public function testDoesNotEmitMissingCheckInAlertBeforeTheConfiguredDelay(): void
    {
        // Shift started 5 min ago; default delay is 10 min.
        $shift = $this->createShift('07:55:00', '11:55:00');
        $this->createApprovedAssignment($shift, ['confirmed_at' => now()]);
        $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value,
        ]);
        $this->assertNull($shift->fresh()->missing_check_in_alerted_at);
    }

    public function testEmitsMissingCheckInAlertAfterTheConfiguredDelay(): void
    {
        // Shift started 15 min ago; default delay is 10 min.
        $shift = $this->createShift('07:45:00', '11:45:00');
        $this->createApprovedAssignment($shift, ['confirmed_at' => now()]);
        $admin = $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value,
            'user_id' => $admin->id,
        ]);
        $this->assertNotNull($shift->fresh()->missing_check_in_alerted_at);
    }

    public function testDoesNotEmitMissingCheckInAlertAfterTheShiftHasEnded(): void
    {
        // Shift 06:00 -> 07:30, already ended by 08:00.
        $shift = $this->createShift('06:00:00', '07:30:00');
        $this->createApprovedAssignment($shift, ['confirmed_at' => now()]);
        $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value,
        ]);
        $this->assertNull($shift->fresh()->missing_check_in_alerted_at);
    }

    public function testExcludesGlobalAdminsUnscopedRolesAndOtherWorkplaceAdministrators(): void
    {
        $shift = $this->createShift('09:00:00', '13:00:00');
        $this->createApprovedAssignment($shift);

        // Global admin, granted the shift permission via the seeded role but excluded by name.
        $globalAdminRole = Role::where('name', RoleName::GlobalAdmin->value)->first();
        $shiftPermission = Permission::where('name', 'shift.list')->first();
        $globalAdminRole->permissions()->attach($shiftPermission);
        $globalAdmin = User::factory()->create();
        $globalAdmin->addRole($globalAdminRole, $this->workplace->id);

        // Unscoped shift role (team_id null).
        $unscopedAdmin = User::factory()->create();
        $unscopedAdmin->addRole($this->shiftManagerRole, null);

        // Administrator scoped to a different workplace.
        $otherWorkplaceAdmin = $this->registerAdmin($this->otherWorkplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', ['user_id' => $globalAdmin->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $unscopedAdmin->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherWorkplaceAdmin->id]);
    }

    public function testStampsAndEmitsNothingWhenTheWorkplaceHasNoScopedAdministrator(): void
    {
        $shift = $this->createShift('09:00:00', '13:00:00');
        $this->createApprovedAssignment($shift);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', ['type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value]);
        $this->assertNotNull($shift->fresh()->unconfirmed_alerted_at);
    }

    public function testLeavesTimestampNullWhenNotificationCreationFailsMidTransactionAndEmitsOnTheFollowingRun(): void
    {
        $shift = $this->createShift('09:00:00', '13:00:00');
        $this->createApprovedAssignment($shift);
        $admin = $this->registerAdmin($this->workplace);

        // Force a foreign-key failure inside the per-shift transaction: the role_user pivot
        // row still resolves this deleted id as an administrator, but writing it into
        // notifications.user_id violates the FK, so the whole per-shift transaction rolls back.
        DB::table('users')->where('id', $admin->id)->delete();

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseMissing('notifications', ['type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value]);
        $this->assertNull($shift->fresh()->unconfirmed_alerted_at);

        // The dangling role_user row (for the now-deleted user) is what caused the failure;
        // clearing it and adding a valid administrator simulates the underlying issue being
        // resolved before the next run.
        DB::table('role_user')->where('user_id', $admin->id)->delete();
        $this->registerAdmin($this->workplace);
        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseHas('notifications', ['type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value]);
        $this->assertNotNull($shift->fresh()->unconfirmed_alerted_at);
    }

    public function testResolvesBothWindowsCorrectlyForAnOvernightShift(): void
    {
        // Shift crosses midnight: 22:00 -> 06:00, on 2026-06-21. Now is 20:00 on 06-21, i.e.
        // 120 min before the real overnight start — inside the 120-min unconfirmed window
        // only when the window is measured against the real start, not the calendar date.
        Carbon::setTestNow('2026-06-21 20:00:00');
        $shift = $this->createShift('22:00:00', '06:00:00', '2026-06-21');
        $this->createApprovedAssignment($shift);
        $admin = $this->registerAdmin($this->workplace);

        // 2 hours before the real start -> inside the default 120-min unconfirmed window.
        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_UNCONFIRMED_ALERT->value,
            'user_id' => $admin->id,
        ]);
    }

    public function testEmitsMissingCheckInAlertForOvernightShiftShortlyAfterMidnight(): void
    {
        // Shift on 06-21 runs 23:55 -> 08:00 (rolls into 06-22). Now is 00:05 on 06-22, i.e.
        // exactly the configured 10-min delay past the real start. Because shifts.date is
        // still 06-21, this only surfaces as a candidate if the query looks a day back from
        // "now" rather than only from shifts.date >= today.
        Carbon::setTestNow('2026-06-22 00:05:00');
        $shift = $this->createShift('23:55:00', '08:00:00', '2026-06-21');
        $this->createApprovedAssignment($shift, ['confirmed_at' => now()]);
        $admin = $this->registerAdmin($this->workplace);

        (new ShiftAdminAlert())->handle();

        $this->assertDatabaseHas('notifications', [
            'type' => NotificationType::SHIFT_MISSING_CHECK_IN_ALERT->value,
            'user_id' => $admin->id,
        ]);
        $this->assertNotNull($shift->fresh()->missing_check_in_alerted_at);
    }

    private function createShift(string $start, string $end, string $date = '2026-06-22'): Shift
    {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'is_active' => true,
        ]);

        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->creator->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 0,
            'temp_total' => 5,
        ]);
    }

    private function createApprovedAssignment(Shift $shift, array $extra = []): ShiftUser
    {
        $member = User::factory()->create();

        return ShiftUser::create(
            array_merge(
                [
                    'shift_id' => $shift->id,
                    'user_id' => $member->id,
                    'workplace_position_id' => $this->position->id,
                    'status' => ShiftUserStatus::Approved->value,
                ],
                $extra
            )
        );
    }

    private function registerAdmin(Workplace $workplace): User
    {
        $admin = User::factory()->create();
        $admin->addRole($this->shiftManagerRole, $workplace->id);

        return $admin;
    }
}
