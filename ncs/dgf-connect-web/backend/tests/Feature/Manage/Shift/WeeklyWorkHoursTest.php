<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\ShiftUserStatus;
use App\Exports\WeeklyWorkHoursExport;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class WeeklyWorkHoursTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private const ENDPOINT = '/manage/shifts/weekly-work-hours';

    private const EXPORT_ENDPOINT = '/manage/shifts/weekly-work-hours/export';

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $position;

    /** @var array<string, Shift> */
    private array $shifts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 08:00:00');
        config(['work.max_hour_per_week' => 28]);

        $this->manager = User::factory()->create();
        $this->giveGlobalPermission($this->manager, PermissionName::ShiftList);

        [$this->workplace, $this->workplaceShift, $this->position] = $this->createWorkplace('Main');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBuildsOneSevenDayWindowPerStartDateInsideTheRange(): void
    {
        $employee = $this->createEmployee('1001');
        $this->assign('2026-09-01', $employee);
        $this->assign('2026-09-02', $employee);
        $this->assign('2026-09-03', $employee);
        $this->assign('2026-09-04', $employee);
        $this->assign('2026-09-10', $employee);
        $this->assign('2026-09-05', $employee, ShiftUserStatus::Pending);
        $this->assign('2026-09-06', $employee, isAbsent: true);

        $response = $this->search(['dates' => ['2026-09-02', '2026-09-10']]);

        $response->assertOk();
        $response->assertJsonPath('data.weeks', [
            ['start' => '2026-09-02', 'end' => '2026-09-08'],
            ['start' => '2026-09-03', 'end' => '2026-09-09'],
            ['start' => '2026-09-04', 'end' => '2026-09-10'],
        ]);
        $response->assertJsonPath('data.total', 1);

        $row = $response->json('data.data.0');
        $this->assertSame($employee->id, $row['user']['id']);
        $this->assertSame('1001', $row['user']['staff_no']);
        $this->assertArrayHasKey('profile_picture', $row['user']);
        $this->assertSame([27, 18, 18], array_map(fn($week) => (int) $week['total_hours'], $row['weeks']));
        $this->assertSame(['near', 'normal', 'normal'], array_column($row['weeks'], 'status'));
        $this->assertSame([28, 28, 28], array_column($row['weeks'], 'max_hour_per_week'));
        $this->assertCount(9, $row['days']);
        $this->assertSame('2026-09-02', $row['days'][0]['date']);
        $this->assertEqualsWithDelta(9.0, $row['days'][0]['hours'], 0.01);
        $this->assertSame('2026-09-10', $row['days'][8]['date']);
        $this->assertArrayNotHasKey('days', $row['weeks'][0]);
        $this->assertSame('near', $row['status']);
    }

    public function testRangeShorterThanSevenDaysStillProducesOneFullWindow(): void
    {
        $employee = $this->createEmployee('1001');
        $this->assign('2026-09-02', $employee);
        $this->assign('2026-09-08', $employee);

        $response = $this->search(['dates' => ['2026-09-02', '2026-09-04']]);

        $response->assertOk();
        $response->assertJsonPath('data.weeks', [['start' => '2026-09-02', 'end' => '2026-09-08']]);
        $this->assertEqualsWithDelta(18.0, $response->json('data.data.0.weeks.0.total_hours'), 0.01);
        $response->assertJsonPath('data.data.0.status', 'normal');
    }

    public function testCountsActualHoursRecordedThroughCheckInAndOut(): void
    {
        $this->giveGlobalPermission($this->manager, PermissionName::ShiftCheckIn);
        $employee = $this->createEmployee('1001');
        $shiftUser = $this->assign('2026-09-01', $employee);
        $this->assign('2026-09-02', $employee);

        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-in", [
                'date' => '2026-09-01',
                'time' => '09:00',
            ])
            ->assertOk();
        $this->actingAs($this->manager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-out", [
                'date' => '2026-09-01',
                'time' => '13:00',
            ])
            ->assertOk();

        $response = $this->search(['dates' => ['2026-08-31', '2026-09-06']]);

        $response->assertOk();
        $row = $response->json('data.data.0');
        $week = $row['weeks'][0];
        $this->assertEqualsWithDelta(13.0, $week['total_hours'], 0.01);
        $this->assertEqualsWithDelta(4.0, $week['actual_hours'], 0.01);
        $this->assertEqualsWithDelta(18.0, $week['scheduled_hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $row['days'][1]['scheduled_hours'], 0.01);
        $this->assertEqualsWithDelta(4.0, $row['days'][1]['hours'], 0.01);
        $this->assertEqualsWithDelta(4.0, $row['days'][1]['actual_hours'], 0.01);
        $this->assertEqualsWithDelta(9.0, $row['days'][2]['hours'], 0.01);
        $this->assertEqualsWithDelta(0.0, $row['days'][2]['actual_hours'], 0.01);
    }

    public function testRollingWindowsCatchOverworkSpanningTwoCalendarWeeks(): void
    {
        $employee = $this->createEmployee('1001');
        foreach (['2026-09-03', '2026-09-04', '2026-09-05', '2026-09-07', '2026-09-08'] as $date) {
            $this->assign($date, $employee);
        }

        $response = $this->search(['dates' => ['2026-08-31', '2026-09-13']]);

        $response->assertOk();
        $row = $response->json('data.data.0');
        $this->assertSame(
            ['near', 'over', 'over', 'over', 'over', 'near', 'normal', 'normal'],
            array_column($row['weeks'], 'status')
        );
        $this->assertSame('2026-09-02', $row['weeks'][2]['start']);
        $this->assertSame('2026-09-08', $row['weeks'][2]['end']);
        $this->assertEqualsWithDelta(45.0, $row['weeks'][2]['total_hours'], 0.01);
        $this->assertArrayNotHasKey('peak_window', $row);
        $this->assertSame('over', $row['status']);
    }

    public function testWindowLimitFollowsTheCustomLimitPeriod(): void
    {
        $employee = $this->createEmployee('1001');
        UserConfig::create([
            'user_id' => $employee->id,
            'max_shift_per_day' => 1,
            'max_hour_per_week' => 40,
            'max_hour_per_week_start_date' => '2026-08-31',
            'max_hour_per_week_expiry_date' => '2026-09-06',
        ]);
        foreach (['2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->assign($date, $employee);
        }

        $response = $this->search(['dates' => ['2026-08-31', '2026-09-13']]);

        $response->assertOk();
        $row = $response->json('data.data.0');
        $this->assertEqualsWithDelta(36.0, $row['weeks'][0]['total_hours'], 0.01);
        $this->assertSame('2026-08-31', $row['weeks'][0]['start']);
        $this->assertSame(40, $row['weeks'][0]['max_hour_per_week']);
        $this->assertSame(28, $row['weeks'][1]['max_hour_per_week']);
        $this->assertSame('near', $row['status']);
    }

    public function testCustomLimitAppliesOnlyWithinItsPeriod(): void
    {
        $employee = $this->createEmployee('1001');
        UserConfig::create([
            'user_id' => $employee->id,
            'max_shift_per_day' => 1,
            'max_hour_per_week' => 40,
            'max_hour_per_week_start_date' => '2026-08-31',
            'max_hour_per_week_expiry_date' => '2026-09-09',
        ]);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->assign($date, $employee);
        }

        $response = $this->search(['dates' => ['2026-08-31', '2026-09-20']]);

        $response->assertOk();
        $weeks = $response->json('data.data.0.weeks');
        $this->assertCount(15, $weeks);
        $this->assertSame([40, 40, 40, 40, 28], array_slice(array_column($weeks, 'max_hour_per_week'), 0, 5));
        $this->assertSame([true, true, true, true, false], array_slice(array_column($weeks, 'is_custom_limit'), 0, 5));
        $this->assertSame(
            [false, false, false, false, true],
            array_slice(array_column($weeks, 'is_limit_changed'), 0, 5)
        );
        $this->assertTrue($weeks[9]['is_limit_changed']);
        $this->assertFalse($weeks[10]['is_limit_changed']);
        $this->assertSame('near', $weeks[0]['status']);
        $this->assertSame('normal', $weeks[4]['status']);
    }

    public function testCustomLimitWithoutPeriodAppliesToEveryWeek(): void
    {
        $employee = $this->createEmployee('1001');
        UserConfig::create([
            'user_id' => $employee->id,
            'max_shift_per_day' => 1,
            'max_hour_per_week' => 20,
        ]);
        $this->assign('2026-09-01', $employee);
        $this->assign('2026-09-02', $employee);
        $this->assign('2026-09-03', $employee);

        $response = $this->search(['dates' => ['2026-08-31', '2026-09-13']]);

        $response->assertOk();
        $weeks = $response->json('data.data.0.weeks');
        $this->assertSame(array_fill(0, 8, 20), array_column($weeks, 'max_hour_per_week'));
        $this->assertSame('over', $weeks[0]['status']);
    }

    public function testFiltersByStaffNoAndLatestResidenceStatus(): void
    {
        $student = $this->createEmployee('1001');
        $student->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => '2027-03-31',
            'residence_status_2' => ResidenceStatus::PermanentResident->value,
            'residence_expiry_date_2' => '2025-03-31',
        ]);
        $formerStudent = $this->createEmployee('1002');
        $formerStudent->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_expiry_date_1' => '2024-03-31',
            'residence_status_2' => ResidenceStatus::PermanentResident->value,
            'residence_expiry_date_2' => '2030-03-31',
        ]);
        $this->createEmployee('1003');
        $this->assign('2026-09-01', $student);
        $this->assign('2026-09-01', $formerStudent);

        $byResidence = $this->search([
            'dates' => ['2026-08-31', '2026-09-06'],
            'residence_statuses' => [ResidenceStatus::StudyAbroad->value],
        ]);
        $byResidence->assertOk();
        $this->assertSame([$student->id], array_column(array_column($byResidence->json('data.data'), 'user'), 'id'));
        $byResidence->assertJsonPath('data.data.0.residence_status', ResidenceStatus::StudyAbroad->value);

        $byStaffNo = $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'staff_no' => '1002']);
        $byStaffNo->assertOk();
        $this->assertSame(
            [$formerStudent->id],
            array_column(array_column($byStaffNo->json('data.data'), 'user'), 'id')
        );
    }

    public function testLoadStatusFilterKeepsOnlyMatchingEmployees(): void
    {
        $overworked = $this->createEmployee('1001');
        $nearLimit = $this->createEmployee('1002');
        $relaxed = $this->createEmployee('1003');
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->assign($date, $overworked);
        }
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->assign($date, $nearLimit);
        }
        $this->assign('2026-09-01', $relaxed);

        $over = $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'load_status' => 'over']);
        $over->assertOk();
        $over->assertJsonPath('data.total', 1);
        $over->assertJsonPath('data.data.0.user.id', $overworked->id);

        $near = $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'load_status' => 'near']);
        $near->assertOk();
        $this->assertSame([$nearLimit->id], array_column(array_column($near->json('data.data'), 'user'), 'id'));

        $normal = $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'load_status' => 'normal']);
        $normal->assertOk();
        $this->assertSame([$relaxed->id], array_column(array_column($normal->json('data.data'), 'user'), 'id'));

        $all = $this->search(['dates' => ['2026-08-31', '2026-09-06']]);
        $all->assertJsonPath('data.total', 3);
    }

    public function testPaginatesInNaturalStaffNoOrder(): void
    {
        $tenth = $this->createEmployee('10');
        $ninth = $this->createEmployee('9');
        $this->assign('2026-09-01', $tenth);
        $this->assign('2026-09-01', $ninth);

        $firstPage = $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'page_size' => 1]);
        $firstPage->assertOk();
        $firstPage->assertJsonPath('data.total', 2);
        $firstPage->assertJsonPath('data.last_page', 2);
        $firstPage->assertJsonPath('data.data.0.user.id', $ninth->id);

        $secondPage = $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'page_size' => 1, 'page' => 2]);
        $secondPage->assertJsonPath('data.data.0.user.id', $tenth->id);
    }

    public function testScopedManagerOnlySeesEmployeesWorkingInAccessibleWorkplaces(): void
    {
        [$otherWorkplace, $otherWorkplaceShift, $otherPosition] = $this->createWorkplace('Other');
        $sharedEmployee = $this->createEmployee('1001');
        $otherOnlyEmployee = $this->createEmployee('1002');
        $this->assign('2026-09-01', $sharedEmployee);
        $this->assignAt($otherWorkplace, $otherWorkplaceShift, $otherPosition, '2026-09-02', $sharedEmployee);
        $this->assignAt($otherWorkplace, $otherWorkplaceShift, $otherPosition, '2026-09-02', $otherOnlyEmployee);

        $scopedManager = User::factory()->create();
        $this->giveWorkplaceScopedPermission($scopedManager, PermissionName::ShiftList, $this->workplace->id);

        $response = $this->search(['dates' => ['2026-08-31', '2026-09-06']], $scopedManager);
        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.user.id', $sharedEmployee->id);
        $this->assertEqualsWithDelta(18.0, $response->json('data.data.0.weeks.0.total_hours'), 0.01);

        $otherWorkplaceOnly = $this->search(
            ['dates' => ['2026-08-31', '2026-09-06'], 'workplace_id' => $otherWorkplace->id],
            $scopedManager
        );
        $otherWorkplaceOnly->assertOk();
        $otherWorkplaceOnly->assertJsonPath('data.total', 0);

        $globalByWorkplace = $this->search([
            'dates' => ['2026-08-31', '2026-09-06'],
            'workplace_id' => $otherWorkplace->id,
        ]);
        $globalByWorkplace->assertJsonPath('data.total', 2);
    }

    public function testRequiresShiftListPermission(): void
    {
        $stranger = User::factory()->create();

        $this->search(['dates' => ['2026-08-31', '2026-09-06']], $stranger)->assertStatus(403);
    }

    public function testValidatesFilters(): void
    {
        $this->search([])->assertStatus(422);
        $this->search(['dates' => ['2026-09-10', '2026-09-01']])->assertStatus(422);
        $this->search(['dates' => ['2026-01-01', '2026-06-30']])->assertStatus(422);
        $this->search(['dates' => ['2026-09-01', '2026-10-06']])->assertStatus(422);
        $this->search(['dates' => ['2026-09-01', '2026-10-05']])->assertOk();
        $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'residence_statuses' => ['unknown']])->assertStatus(
            422
        );
        $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'load_status' => 'unknown'])->assertStatus(422);
        $this->search(['dates' => ['2026-08-31', '2026-09-06'], 'load_status' => 'near_or_over'])->assertStatus(422);
    }

    public function testExportRequiresBothShiftListAndShiftExportPermissions(): void
    {
        $this->export(['dates' => ['2026-08-31', '2026-09-06']])->assertStatus(403);
        $this->export(['dates' => ['2026-08-31', '2026-09-06']], User::factory()->create())->assertStatus(403);

        $exportOnly = User::factory()->create();
        $this->giveGlobalPermission($exportOnly, PermissionName::ShiftExport);
        $this->export(['dates' => ['2026-08-31', '2026-09-06']], $exportOnly)->assertStatus(403);
    }

    public function testExportValidatesFiltersLikeTheListing(): void
    {
        $this->giveGlobalPermission($this->manager, PermissionName::ShiftExport);

        $this->export([])->assertStatus(422);
        $this->export(['dates' => ['2026-09-01', '2026-10-06']])->assertStatus(422);
    }

    public function testExportContainsEveryEmployeeMatchingTheCurrentFilterRegardlessOfPaging(): void
    {
        Excel::fake();
        $this->giveGlobalPermission($this->manager, PermissionName::ShiftExport);
        $overworked = $this->createEmployee('1001');
        $nearLimit = $this->createEmployee('1002');
        $relaxed = $this->createEmployee('1003');
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->assign($date, $overworked);
        }
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->assign($date, $nearLimit);
        }
        $this->assign('2026-09-01', $relaxed);

        $this->export(['dates' => ['2026-08-31', '2026-09-06'], 'page_size' => 1])->assertOk();

        Excel::assertDownloaded('weekly_work_hours_2026-08-31_2026-09-06.xlsx', function (
            WeeklyWorkHoursExport $export
        ) {
            $rows = $export->array();
            $this->assertSame(['1001', '1002', '1003'], array_column($rows, 0));
            $this->assertSame(
                [
                    __('weekly_work_hours.status_label.over'),
                    __('weekly_work_hours.status_label.near'),
                    __('weekly_work_hours.status_label.normal'),
                ],
                array_column($rows, 3)
            );
            $this->assertSame('36h / 28h', $rows[0][4]);
            $this->assertSame(__('weekly_work_hours.staff_no'), $export->headings()[0]);

            return true;
        });
    }

    public function testExportAppliesTheStatusAndStaffNoFilters(): void
    {
        Excel::fake();
        $this->giveGlobalPermission($this->manager, PermissionName::ShiftExport);
        $overworked = $this->createEmployee('1001');
        $relaxed = $this->createEmployee('1003');
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->assign($date, $overworked);
        }
        $this->assign('2026-09-01', $relaxed);

        $this->export(['dates' => ['2026-08-31', '2026-09-06'], 'load_status' => 'over'])->assertOk();
        Excel::assertDownloaded('weekly_work_hours_2026-08-31_2026-09-06.xlsx', function (
            WeeklyWorkHoursExport $export
        ) {
            $this->assertSame(['1001'], array_column($export->array(), 0));

            return true;
        });

        $this->export(['dates' => ['2026-08-31', '2026-09-06'], 'staff_no' => '1003'])->assertOk();
        Excel::assertDownloaded('weekly_work_hours_2026-08-31_2026-09-06.xlsx', function (
            WeeklyWorkHoursExport $export
        ) {
            $this->assertSame(['1003'], array_column($export->array(), 0));

            return true;
        });
    }

    private function export(array $params, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager, 'api')->get(
            self::EXPORT_ENDPOINT . '?' . http_build_query($params)
        );
    }

    private function search(array $params, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->manager, 'api')->getJson(
            self::ENDPOINT . '?' . http_build_query($params)
        );
    }

    /**
     * @return array{0: Workplace, 1: WorkplaceShift, 2: WorkplacePosition}
     */
    private function createWorkplace(string $name): array
    {
        $workplace = Workplace::create([
            'name' => $name,
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
        $position = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        return [$workplace, $workplaceShift, $position];
    }

    private function createEmployee(string $staffNo): User
    {
        $employee = User::factory()->create(['staff_no' => $staffNo]);
        $this->workplace->users()->attach($employee->id);

        return $employee->load('info');
    }

    private function assign(
        string $date,
        User $employee,
        ShiftUserStatus $status = ShiftUserStatus::Approved,
        bool $isAbsent = false
    ): ShiftUser {
        return $this->assignAt(
            $this->workplace,
            $this->workplaceShift,
            $this->position,
            $date,
            $employee,
            $status,
            $isAbsent
        );
    }

    private function assignAt(
        Workplace $workplace,
        WorkplaceShift $workplaceShift,
        WorkplacePosition $position,
        string $date,
        User $employee,
        ShiftUserStatus $status = ShiftUserStatus::Approved,
        bool $isAbsent = false
    ): ShiftUser {
        $shift = $this->shifts["{$workplaceShift->id}:{$date}"] ??= Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 10,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => $status->value,
            'is_absent' => $isAbsent,
        ]);
    }
}
