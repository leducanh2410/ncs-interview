<?php

namespace Tests\Feature\Manage;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Exports\WorkHistoryDetailExport;
use App\Jobs\CreateShiftReport;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class WorkHistoryAttendanceStatusTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $position;

    private CheckIn $onTime;

    private CheckIn $late;

    private CheckIn $early;

    private CheckIn $absent;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-12 20:00:00');

        $this->manager = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        foreach ([PermissionName::WorkHistoryList, PermissionName::WorkHistoryExport] as $permName) {
            $permission = Permission::firstOrCreate(['name' => $permName->value], ['display_name' => $permName->value]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->manager->addRole($role);

        $this->workplace = Workplace::create([
            'name' => 'Status Workplace',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_duration' => 0,
        ]);
        $this->position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);

        $this->onTime = $this->createCheckIn('2026-05-07', '09:00:00', '18:00:00');
        $this->late = $this->createCheckIn('2026-05-08', '09:25:00', '18:00:00');
        $this->late->update(['late_reason' => 'Train delay']);
        $this->early = $this->createCheckIn('2026-05-09', '09:00:00', '17:15:00');
        $this->early->update(['early_reason' => 'Sick']);
        $this->absent = $this->createCheckIn('2026-05-10', '09:00:00', '18:00:00', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testListExposesDeviationAndReasons(): void
    {
        $rows = collect(
            $this->actingAs($this->manager, 'api')->getJson('/manage/work-histories')->assertOk()->json('data.data')
        )->keyBy('id');

        $this->assertSame(25, $rows[$this->late->id]['late_minutes']);
        $this->assertSame('Train delay', $rows[$this->late->id]['late_reason']);
        $this->assertSame(45, $rows[$this->early->id]['early_minutes']);
        $this->assertSame('Sick', $rows[$this->early->id]['early_reason']);
        $this->assertSame(0, $rows[$this->onTime->id]['late_minutes']);
        $this->assertTrue($rows[$this->absent->id]['is_absent']);
    }

    public function testFiltersByAttendanceStatus(): void
    {
        $this->assertSame([$this->late->id], $this->filteredIds('late'));
        $this->assertSame([$this->early->id], $this->filteredIds('early_leave'));
        $this->assertSame([$this->absent->id], $this->filteredIds('absent'));
    }

    public function testRejectsUnknownAttendanceStatus(): void
    {
        $this->actingAs($this->manager, 'api')
            ->getJson('/manage/work-histories?attendance_status=unknown')
            ->assertUnprocessable();
    }

    public function testDetailExportIncludesStatusAndReasonColumns(): void
    {
        $data = app(WorkHistoryService::class)->buildDetailExportData(
            ['dates' => ['2026-05-01', '2026-05-12']],
            $this->manager
        );
        $rows = collect($data[$this->workplace->id])->keyBy('check_in_id');

        $this->assertSame('Đi muộn (25 phút)', $rows[$this->late->id]['attendance_status']);
        $this->assertSame('Đi muộn: Train delay', $rows[$this->late->id]['attendance_reason']);
        $this->assertSame('Về sớm (45 phút)', $rows[$this->early->id]['attendance_status']);
        $this->assertSame('Về sớm: Sick', $rows[$this->early->id]['attendance_reason']);
        $this->assertSame('Bỏ ca', $rows[$this->absent->id]['attendance_status']);
        $this->assertSame('', $rows[$this->onTime->id]['attendance_status']);
        $this->assertSame('', $rows[$this->onTime->id]['attendance_reason']);

        $headings = (new WorkHistoryDetailExport([]))->headings()[0];
        $this->assertSame(['Check_In_ID', 'Trạng thái', 'Lý do'], array_slice($headings, 16));
    }

    public function testDetailExportJoinsBothReasonsOnOneLine(): void
    {
        $this->late->update(['check_out_time' => '2026-05-08 17:50:00', 'early_reason' => 'Bus']);

        $data = app(WorkHistoryService::class)->buildDetailExportData(
            ['dates' => ['2026-05-01', '2026-05-12']],
            $this->manager
        );
        $row = collect($data[$this->workplace->id])->firstWhere('check_in_id', $this->late->id);

        $this->assertSame('Đi muộn (25 phút), Về sớm (10 phút)', $row['attendance_status']);
        $this->assertSame('Đi muộn: Train delay; Về sớm: Bus', $row['attendance_reason']);
    }

    public function testChangingShiftTemplateTimesRecalculatesOnlyRecentShifts(): void
    {
        $recent = $this->createCheckIn('2026-05-11', '09:25:00', '18:00:00');

        $this->workplaceShift->update(['start_time' => '09:30:00']);

        $this->assertSame(0, $recent->refresh()->late_minutes);
        $this->assertSame(25, $this->late->refresh()->late_minutes);
        $this->assertSame('Train delay', $this->late->late_reason);

        $this->workplaceShift->update(['start_time' => '08:00:00', 'end_time' => '18:30:00']);

        $this->assertSame(85, $recent->refresh()->late_minutes);
        $this->assertSame(30, $recent->early_minutes);
        $this->assertSame(0, $this->onTime->refresh()->early_minutes);
    }

    public function testChangingShiftTemplateTimesUpdatesFiledReportsOfRecentShifts(): void
    {
        $recent = $this->createCheckIn('2026-05-11', '09:25:00', '18:00:00');
        app()->call([new CreateShiftReport($recent->shift_user_id), 'handle']);
        app()->call([new CreateShiftReport($this->late->shift_user_id), 'handle']);
        $this->assertEquals(25, $this->reportOf($recent)->late_minutes);

        $this->workplaceShift->update(['start_time' => '09:30:00']);

        $this->assertNull($this->reportOf($recent)->late_minutes);
        $this->assertEquals(25, $this->reportOf($this->late)->late_minutes);

        $this->workplaceShift->update(['start_time' => '09:00:00', 'end_time' => '18:30:00']);

        $report = $this->reportOf($recent);
        $this->assertEquals(25, $report->late_minutes);
        $this->assertEquals(30, $report->early_minutes);
    }

    public function testReasonsAreHiddenOnceCorrectedTimesRemoveTheDeviation(): void
    {
        $service = app(WorkHistoryService::class);
        $service->applyValues(
            $this->late,
            [
                'check_in_date' => '2026-05-08',
                'check_in_time' => '09:00:00',
                'check_out_date' => '2026-05-08',
                'check_out_time' => '18:00:00',
            ],
            $this->manager->id
        );
        $service->applyValues(
            $this->early,
            ['check_in_date' => '2026-05-09', 'check_in_time' => '09:00:00', 'clear_check_out' => true],
            $this->manager->id
        );

        $data = $service->buildDetailExportData(['dates' => ['2026-05-01', '2026-05-12']], $this->manager);
        $rows = collect($data[$this->workplace->id])->keyBy('check_in_id');

        $this->assertSame('Train delay', $this->late->refresh()->late_reason);
        $this->assertSame('', $rows[$this->late->id]['attendance_status']);
        $this->assertSame('', $rows[$this->late->id]['attendance_reason']);
        $this->early->refresh();
        $this->assertSame(0, $this->early->early_minutes);
        $this->assertSame('Sick', $this->early->early_reason);
        $this->assertArrayNotHasKey($this->early->id, $rows->all());
    }

    public function testWorkplaceScopedManagerOnlySeesOwnWorkplaceWhenFiltering(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $otherLate = $this->createCheckIn(
            '2026-05-08',
            '09:40:00',
            '18:00:00',
            workplaceShift: WorkplaceShift::create([
                'workplace_id' => $otherWorkplace->id,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'break_duration' => 0,
            ]),
            position: WorkplacePosition::create(['workplace_id' => $otherWorkplace->id, 'name' => 'Staff'])
        );
        $scopedManager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($scopedManager, PermissionName::WorkHistoryList, $this->workplace->id);
        $this->giveWorkplaceScopedPermission($scopedManager, PermissionName::WorkHistoryExport, $this->workplace->id);

        $this->assertEqualsCanonicalizing([$this->late->id, $otherLate->id], $this->filteredIds('late'));
        $this->assertSame([$this->late->id], $this->filteredIds('late', $scopedManager));

        $data = app(WorkHistoryService::class)->buildDetailExportData(
            ['dates' => ['2026-05-01', '2026-05-12'], 'attendance_status' => 'late'],
            $scopedManager
        );
        $this->assertSame([$this->workplace->id], array_keys($data));
        $this->assertSame([$this->late->id], array_column($data[$this->workplace->id], 'check_in_id'));
    }

    public function testDetailExportRespectsAttendanceStatusFilter(): void
    {
        $data = app(WorkHistoryService::class)->buildDetailExportData(
            ['dates' => ['2026-05-01', '2026-05-12'], 'attendance_status' => 'late'],
            $this->manager
        );

        $this->assertSame([$this->late->id], array_column($data[$this->workplace->id], 'check_in_id'));
    }

    private function reportOf(CheckIn $checkIn): ShiftReport
    {
        return ShiftReport::query()->where('shift_user_id', $checkIn->shift_user_id)->firstOrFail();
    }

    private function filteredIds(string $status, ?User $actor = null): array
    {
        return collect(
            $this->actingAs($actor ?? $this->manager, 'api')
                ->getJson("/manage/work-histories?attendance_status={$status}")
                ->assertOk()
                ->json('data.data')
        )
            ->pluck('id')
            ->all();
    }

    private function createCheckIn(
        string $date,
        string $checkInTime,
        string $checkOutTime,
        bool $isAbsent = false,
        ?WorkplaceShift $workplaceShift = null,
        ?WorkplacePosition $position = null
    ): CheckIn {
        $workplaceShift ??= $this->workplaceShift;
        $user = User::factory()->create();
        $shift = Shift::create([
            'workplace_id' => $workplaceShift->workplace_id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => ($position ?? $this->position)->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => $isAbsent,
        ]);

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $user->id,
            'check_in_time' => "{$date} {$checkInTime}",
            'check_out_time' => "{$date} {$checkOutTime}",
            'work_minutes' => 480,
        ])->refresh();
    }
}
