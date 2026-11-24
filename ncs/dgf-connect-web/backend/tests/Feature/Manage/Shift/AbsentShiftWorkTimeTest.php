<?php

namespace Tests\Feature\Manage\Shift;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Exports\ShiftExport;
use App\Facades\SalaryFacade;
use App\Jobs\CollectWorkTime;
use App\Jobs\UpdateUserSalary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Salary;
use App\Models\SalaryRequest;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkTimeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the full lifecycle: an admin records the attendance on behalf of an employee
 * through the real endpoints, then marks the shift as absent. The recorded check-in is
 * kept, but it must stop counting towards the monthly work time report and the salary.
 */
class AbsentShiftWorkTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $position;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 20:00:00');

        $this->manager = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        foreach (
            [
                PermissionName::ShiftCheckIn,
                PermissionName::UserList,
                PermissionName::WorkHistoryList,
                PermissionName::ShiftList,
                PermissionName::ShiftExport,
            ]
            as $permissionName
        ) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName->value],
                ['display_name' => $permissionName->value]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->manager->addRole($role);

        $this->employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'salary_per_hour' => 1000,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->position = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testAbsentShiftStopsCountingTowardsMonthlyReportAndIsRestoredOnUndo(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');

        $workedMinutes = $this->monthlyReport()->work_minutes;
        $this->assertGreaterThan(0, $workedMinutes);
        $this->assertEquals(1, $this->monthlyReport()->work_days);

        $this->toggleAbsent($shiftUser)->assertOk();

        $this->assertTrue($shiftUser->fresh()->is_absent);
        // The check-in itself must survive: admins still need to see that someone showed up.
        $this->assertDatabaseHas('check_ins', [
            'shift_user_id' => $shiftUser->id,
            'work_minutes' => $workedMinutes,
        ]);
        $this->assertEquals(0, $this->monthlyReport()->work_minutes);
        $this->assertEquals(0, $this->monthlyReport()->work_days);

        $this->toggleAbsent($shiftUser)->assertOk();

        $this->assertFalse($shiftUser->fresh()->is_absent);
        $this->assertEquals($workedMinutes, $this->monthlyReport()->work_minutes);
        $this->assertEquals(1, $this->monthlyReport()->work_days);
    }

    public function testAbsentShiftOnlyDeductsItsOwnHoursFromAMonthWithHistory(): void
    {
        $keptShiftUser = $this->createShiftUser('2026-05-06');
        $this->recordAttendance($keptShiftUser, '2026-05-06');
        $keptMinutes = $this->monthlyReport()->work_minutes;

        $absentShiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($absentShiftUser, '2026-05-08');
        $this->assertEquals(2, $this->monthlyReport()->work_days);

        $this->toggleAbsent($absentShiftUser)->assertOk();

        $this->assertEquals($keptMinutes, $this->monthlyReport()->work_minutes);
        $this->assertEquals(1, $this->monthlyReport()->work_days);
    }

    public function testAbsentShiftIsRemovedFromSalary(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');

        SalaryFacade::collectUsersSalary(
            [$this->employee->id],
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31')->endOfDay()
        );

        $salary = Salary::query()->where('user_id', $this->employee->id)->where('month', '2026-05')->first();
        $this->assertNotNull($salary);
        $this->assertGreaterThan(0, (float) $salary->total_amount);

        $this->toggleAbsent($shiftUser)->assertOk();

        $this->assertEquals(0.0, (float) $salary->fresh()->total_amount);
        $this->assertEquals(0, (float) $salary->fresh()->total_minutes);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedSalaryProvider(): array
    {
        return [
            'finalized' => ['is_finalized'],
            'fully paid' => ['is_fully_paid'],
        ];
    }

    #[DataProvider('lockedSalaryProvider')]
    public function testTheResponseSaysWhetherTheSalaryStaysSettled(string $lockField): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $salary = $this->collectSalary();

        $this->toggleAbsent($shiftUser)->assertOk()->assertJsonPath('data.is_salary_settled', false);

        $this->toggleAbsent($shiftUser)->assertOk();
        $salary->update([$lockField => true]);

        // The admin must not be promised a salary change that the recalculation will refuse.
        $this->toggleAbsent($shiftUser)->assertOk()->assertJsonPath('data.is_salary_settled', true);
    }

    #[DataProvider('lockedSalaryProvider')]
    public function testSettledSalaryIsNotOverwrittenWhenAShiftBecomesAbsent(string $lockField): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');

        SalaryFacade::collectUsersSalary(
            [$this->employee->id],
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31')->endOfDay()
        );

        $salary = Salary::query()->where('user_id', $this->employee->id)->where('month', '2026-05')->first();
        $settledAmount = (float) $salary->total_amount;
        $this->assertGreaterThan(0, $settledAmount);
        $salary->update([$lockField => true]);

        $this->toggleAbsent($shiftUser)->assertOk();

        // The money was already agreed or transferred - a later edit must not move it.
        $this->assertEquals($settledAmount, (float) $salary->fresh()->total_amount);
    }

    public function testSettledSalaryIsNotOverwrittenWhenHoursAreRecalculated(): void
    {
        $keptShiftUser = $this->createShiftUser('2026-05-06');
        $this->recordAttendance($keptShiftUser, '2026-05-06');
        $absentShiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($absentShiftUser, '2026-05-08');

        SalaryFacade::collectUsersSalary(
            [$this->employee->id],
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31')->endOfDay()
        );

        $salary = Salary::query()->where('user_id', $this->employee->id)->where('month', '2026-05')->first();
        $settledAmount = (float) $salary->total_amount;
        $salary->update(['is_finalized' => true]);

        // Only one of the two shifts drops out, so the month still has work histories and
        // goes through the normal update path rather than the reset path.
        $this->toggleAbsent($absentShiftUser)->assertOk();

        $this->assertEquals($settledAmount, (float) $salary->fresh()->total_amount);
    }

    public function testTogglingAbsentIsRefusedWhenItWouldDropTheMonthBelowWhatWasAlreadyPaid(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $salary = $this->collectSalary();

        // A partial advance leaves both settle flags false - the state ImportTransferInfoAction
        // produces for an advance that does not clear the month.
        $salary->update(['total_paid' => 5000, 'total_remaining' => $salary->total_amount - 5000]);
        $this->assertFalse((bool) $salary->fresh()->is_finalized);
        $this->assertFalse((bool) $salary->fresh()->is_fully_paid);

        $response = $this->toggleAbsent($shiftUser);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::ShiftUserAbsentSalaryPaid->value);
        $this->assertFalse($shiftUser->fresh()->is_absent);
        $this->assertEquals(5000, (float) $salary->fresh()->total_paid);
    }

    public function testTogglingAbsentIsAllowedWhenTheRemainingShiftsStillCoverWhatWasPaid(): void
    {
        $keptShiftUser = $this->createShiftUser('2026-05-06');
        $this->recordAttendance($keptShiftUser, '2026-05-06');
        $absentShiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($absentShiftUser, '2026-05-08');
        $salary = $this->collectSalary();

        // Paid less than a single shift is worth, so dropping one shift still leaves enough.
        $salary->update(['total_paid' => 1000, 'total_remaining' => $salary->total_amount - 1000]);

        $this->toggleAbsent($absentShiftUser)->assertOk();

        $this->assertTrue($absentShiftUser->fresh()->is_absent);
        $this->assertGreaterThanOrEqual(0, (float) $salary->fresh()->total_remaining);
    }

    public function testUnmarkingAbsentIsNeverBlockedByAPaidMonth(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $salary = $this->collectSalary();
        $salary->update(['total_paid' => 5000, 'total_remaining' => $salary->total_amount - 5000]);

        $shiftUser->update(['is_absent' => true]);

        // Turning absent off only ever adds hours back, so there is nothing to refuse.
        $this->toggleAbsent($shiftUser)->assertOk();
        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function activeSalaryRequestStatusProvider(): array
    {
        return array_combine(
            SalaryRequestStatus::getActiveStatuses(),
            array_map(fn(string $status) => [$status], SalaryRequestStatus::getActiveStatuses())
        );
    }

    #[DataProvider('activeSalaryRequestStatusProvider')]
    public function testTogglingAbsentIsRefusedWhileASalaryRequestIsActive(string $status): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $salary = $this->collectSalary();
        $this->createSalaryRequest($salary, $status);

        $response = $this->toggleAbsent($shiftUser);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::ShiftUserAbsentSalaryRequested->value);
        $this->assertFalse($shiftUser->fresh()->is_absent);
        $this->assertEquals($salary->total_amount, $salary->fresh()->total_amount);
    }

    public function testTogglingAbsentIsAllowedOnceTheSalaryRequestIsNoLongerActive(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $salary = $this->collectSalary();
        $request = $this->createSalaryRequest($salary, SalaryRequestStatus::WaitForApproval->value);

        $this->toggleAbsent($shiftUser)->assertStatus(400);

        $request->update(['status' => SalaryRequestStatus::Cancelled->value]);

        $this->toggleAbsent($shiftUser)->assertOk();
        $this->assertTrue($shiftUser->fresh()->is_absent);
    }

    public function testASalaryRequestForAnotherMonthDoesNotBlockTheToggle(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $salary = $this->collectSalary();

        $otherMonth = Salary::query()->create([
            'user_id' => $this->employee->id,
            'month' => '2026-04',
            'total_amount' => 5000,
            'total_remaining' => 5000,
            'min_salary' => 1000,
            'max_salary' => 1000,
            'total_minutes' => 300,
        ]);
        $this->createSalaryRequest($otherMonth, SalaryRequestStatus::WaitForApproval->value);

        $this->toggleAbsent($shiftUser)->assertOk();

        $this->assertTrue($shiftUser->fresh()->is_absent);
        $this->assertEquals(0.0, (float) $salary->fresh()->total_amount);
    }

    public function testTogglingAbsentDropsTheCachedSalaryRequestLimit(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $this->collectSalary();

        $cacheKey = CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id;
        Cache::put($cacheKey, ['min' => 9000, 'max' => 9000], 3600);

        $this->toggleAbsent($shiftUser)->assertOk();

        // A stale limit would let the employee request more than the month is now worth, and
        // after un-marking absent it would wrongly reject a valid request.
        $this->assertNull(Cache::get($cacheKey));
    }

    public function testTheCachedSalaryRequestLimitIsDroppedBeforeTheQueueRuns(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $this->collectSalary();

        $cacheKey = CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id;
        Cache::put($cacheKey, ['min' => 9000, 'max' => 9000], 3600);

        // On production the recalculation runs on redis, so the employee must not be able to
        // spend the old limit in the window before a worker picks the job up.
        Queue::fake();
        $this->toggleAbsent($shiftUser)->assertOk();

        Queue::assertPushed(UpdateUserSalary::class);
        $this->assertNull(Cache::get($cacheKey));
    }

    public function testTogglingAnOldShiftOnlyReaggregatesItsOwnMonth(): void
    {
        $oldShiftUser = $this->createShiftUser('2026-03-08');
        $this->recordAttendance($oldShiftUser, '2026-03-08', '2026-03-01');
        $recentShiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($recentShiftUser, '2026-05-08');

        // A sentinel that only a re-aggregation of May would overwrite.
        $recentReport = WorkTimeReport::query()
            ->where('user_id', $this->employee->id)
            ->where('month', '2026-05')
            ->firstOrFail();
        $recentReport->update(['work_minutes' => 99999]);

        $this->toggleAbsent($oldShiftUser)->assertOk();

        $marchReport = WorkTimeReport::query()
            ->where('user_id', $this->employee->id)
            ->where('month', '2026-03')
            ->firstOrFail();
        $this->assertEquals(0, $marchReport->work_minutes);
        // Only March changed, so May must be left alone rather than recomputed alongside it.
        $this->assertEquals(99999, $recentReport->fresh()->work_minutes);
    }

    public function testShiftExportDoesNotCountAnAbsentShiftAsADayWorked(): void
    {
        Excel::fake();

        $pastShiftUser = $this->createShiftUser('2026-05-06');
        $this->recordAttendance($pastShiftUser, '2026-05-06');
        $absentShiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($absentShiftUser, '2026-05-08');
        $this->toggleAbsent($absentShiftUser)->assertOk();

        $todayShift = $this->createShiftUser('2026-05-10');
        $this->actingAs($this->manager, 'api')
            ->get("/manage/shifts/{$todayShift->shift_id}/export")
            ->assertOk();

        Excel::assertDownloaded("shift_{$todayShift->shift_id}.xlsx", function (ShiftExport $export) {
            // The employee is the only person on the exported shift. Two check-ins exist for
            // them at this workplace, but the absent one does not count as a day worked.
            $row = $export->collection()->first();

            return $row !== null && (int) $row['number_work_day'] === 1;
        });
    }

    public function testWorkHistoryListShowsZeroHoursAndAnAbsentFlag(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($shiftUser, '2026-05-08');
        $this->toggleAbsent($shiftUser)->assertOk();

        $response = $this->actingAs($this->manager, 'api')->getJson(
            "/manage/work-histories?user_id={$this->employee->id}"
        );

        $response->assertOk();
        $item = collect($response->json('data.data'))->firstWhere('shift_user_id', $shiftUser->id);
        $this->assertNotNull($item);
        $this->assertTrue($item['is_absent']);
        $this->assertEquals(0, $item['work_minutes']);
        // The recorded times stay visible so the admin can double check the decision.
        $this->assertNotNull($item['check_in_time']);
        $this->assertNotNull($item['check_out_time']);
    }

    public function testEmployeeOwnHistoryStopsCountingAnAbsentShift(): void
    {
        $keptShiftUser = $this->createShiftUser('2026-05-06');
        $this->recordAttendance($keptShiftUser, '2026-05-06');

        $absentShiftUser = $this->createShiftUser('2026-05-08');
        $this->recordAttendance($absentShiftUser, '2026-05-08');

        $before = $this->actingAs($this->employee, 'api')->getJson('/user/check-in/history?month=2026-05');
        $before->assertOk();
        $this->assertCount(2, $before->json('data.items'));

        $this->toggleAbsent($absentShiftUser)->assertOk();

        $after = $this->actingAs($this->employee, 'api')->getJson('/user/check-in/history?month=2026-05');
        $after->assertOk();
        $this->assertCount(1, $after->json('data.items'));
        $this->assertEquals((float) $before->json('data.total') / 2, (float) $after->json('data.total'));
    }

    public function testTogglingAbsentWithoutCheckInLeavesReportsUntouched(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');

        $this->toggleAbsent($shiftUser)->assertOk();

        $this->assertTrue($shiftUser->fresh()->is_absent);
        $this->assertNull($this->monthlyReport());
    }

    public function testToggleAbsentRequiresPermission(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08');
        $other = User::factory()->create();

        $this->actingAs($other, 'api')
            ->postJson("/manage/schedules/{$shiftUser->id}/toggle-absent")
            ->assertStatus(403);
    }

    public function testToggleAbsentReturns404ForUnknownShiftUser(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/manage/schedules/999999/toggle-absent')->assertStatus(404);
    }

    public function testToggleAbsentReturns400ForPendingShiftUser(): void
    {
        $shiftUser = $this->createShiftUser('2026-05-08', ShiftUserStatus::Pending->value);

        $this->toggleAbsent($shiftUser)->assertStatus(400);

        $this->assertFalse($shiftUser->fresh()->is_absent);
    }

    private function createShiftUser(string $date, ?string $status = null): ShiftUser
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 1,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $this->employee->id,
            'workplace_position_id' => $this->position->id,
            'status' => $status ?? ShiftUserStatus::Approved->value,
        ]);
    }

    /**
     * Reaches the "checked out with work minutes" state through the real admin endpoints
     * instead of inserting a check-in row directly.
     */
    private function recordAttendance(ShiftUser $shiftUser, string $date, string $collectFrom = '2026-05-01'): void
    {
        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-in", [
                'date' => $date,
                'time' => '09:00',
            ])
            ->assertOk();

        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-out", [
                'date' => $date,
                'time' => '18:00',
            ])
            ->assertOk();

        // Checking out does not aggregate by itself - the scheduled job does. Run it here
        // so the assertions start from the same state production reaches overnight.
        (new CollectWorkTime(Carbon::parse($collectFrom), $this->employee->id))->handle();
    }

    private function toggleAbsent(ShiftUser $shiftUser)
    {
        return $this->actingAs($this->manager, 'api')->postJson("/manage/schedules/{$shiftUser->id}/toggle-absent");
    }

    private function collectSalary(): Salary
    {
        SalaryFacade::collectUsersSalary(
            [$this->employee->id],
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31')->endOfDay()
        );

        return Salary::query()->where('user_id', $this->employee->id)->where('month', '2026-05')->firstOrFail();
    }

    private function createSalaryRequest(Salary $salary, string $status): SalaryRequest
    {
        $request = SalaryRequest::query()->create([
            'user_id' => $salary->user_id,
            'max_amount' => $salary->total_amount,
            'amount' => $salary->total_amount,
            'status' => $status,
            'type' => SalaryRequestType::Transfer->value,
        ]);
        $request->details()->create(['salary_id' => $salary->id, 'amount' => $salary->total_amount]);

        return $request;
    }

    private function monthlyReport(): ?WorkTimeReport
    {
        return WorkTimeReport::query()->where('user_id', $this->employee->id)->where('month', '2026-05')->first();
    }
}
