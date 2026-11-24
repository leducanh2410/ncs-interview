<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\PermissionName;
use App\Exports\SalaryExport;
use App\Models\User;
use App\Models\WorkTimeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class ExportSalariesTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/export';

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryExport);
        $this->employee = User::factory()->create([
            'staff_no' => '0042',
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
            'first_name_kana' => 'タロウ',
            'last_name_kana' => 'ヤマダ',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function testRequiresTheSalaryExportPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function testNamesTheFileAfterTheExportedMonth(): void
    {
        Excel::fake();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . '?month=2026-03')
            ->assertOk();

        Excel::assertDownloaded('salary_202603.xlsx');
    }

    public function testDefaultsToTheCurrentMonth(): void
    {
        Excel::fake();

        $this->actingAs($this->admin, 'api')->get(self::URL)->assertOk();

        Excel::assertDownloaded('salary_202605.xlsx');
    }

    public function testWritesOneRowPerEmployeeWithHoursFormattedAsTime(): void
    {
        Excel::fake();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000, [
            'total_paid' => 1000,
            'total_fee' => 330,
            'total_deductions' => 200,
            'min_salary' => 1000,
            'max_salary' => 1000,
        ]);
        WorkTimeReport::query()->create([
            'user_id' => $this->employee->id,
            'month' => '2026-03',
            'work_days' => 12,
            'work_minutes' => 3000,
            'work_minutes_normal' => 2400,
            'work_minutes_night' => 300,
            'work_minutes_overtime' => 125,
            'work_minutes_night_overtime' => 0,
        ]);

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . '?month=2026-03')
            ->assertOk();

        Excel::assertDownloaded('salary_202603.xlsx', function (SalaryExport $export) {
            $row = $export->collection()->first();

            return $row['staff_no'] === '0042' &&
                $row['work_days'] === 12 &&
                $row['work_hours_normal'] === '40:00' &&
                $row['work_hours_night'] === '5:00' &&
                $row['work_hours_overtime'] === '2:05' &&
                $row['base_salary'] === 1000 &&
                // A single hourly rate means no incentive spread to report.
                $row['diff'] === 0 &&
                // 50000 earned, minus 1000 already paid, 330 fee and 200 deductions.
                $row['total_remaining'] === 48470;
        });
    }

    public function testReportsTheSpreadAsDiffWhenTheHourlyRateVaried(): void
    {
        Excel::fake();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000, [
            'min_salary' => 1000,
            'max_salary' => 1200,
        ]);
        WorkTimeReport::query()->create([
            'user_id' => $this->employee->id,
            'month' => '2026-03',
            'work_days' => 12,
            'work_minutes' => 2400,
            'work_minutes_normal' => 2400,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . '?month=2026-03')
            ->assertOk();

        Excel::assertDownloaded('salary_202603.xlsx', function (SalaryExport $export) {
            // 40 hours at the lowest rate is 40000; everything above that is the incentive.
            // floor() makes the base a float, so the difference comes out as one too.
            return $export->collection()->first()['diff'] == 10000;
        });
    }

    public function testFallsBackToZeroHoursWhenNoWorkTimeReportExists(): void
    {
        Excel::fake();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . '?month=2026-03')
            ->assertOk();

        Excel::assertDownloaded('salary_202603.xlsx', function (SalaryExport $export) {
            $row = $export->collection()->first();

            return $row['work_days'] === null && $row['work_hours_normal'] === '0:00';
        });
    }

    public function testExportsOnlyTheSelectedEmployee(): void
    {
        Excel::fake();
        $other = User::factory()->create(['staff_no' => '0099']);
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createFinalizedSalary($other, '2026-03', 40000);

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . "?month=2026-03&user_id={$this->employee->id}")
            ->assertOk();

        Excel::assertDownloaded('salary_202603.xlsx', function (SalaryExport $export) {
            return $export->collection()->count() === 1 && $export->collection()->first()['staff_no'] === '0042';
        });
    }

    public function testExportsAnEmptySheetWhenTheMonthHasNoSalaries(): void
    {
        Excel::fake();

        $this->actingAs($this->admin, 'api')
            ->get(self::URL . '?month=2026-01')
            ->assertOk();

        Excel::assertDownloaded('salary_202601.xlsx', function (SalaryExport $export) {
            return $export->collection()->isEmpty();
        });
    }

    public function testRejectsAMonthThatIsNotYearDashMonth(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?month=March');

        $response->assertStatus(422);
        $this->assertArrayHasKey('month', $response->json('data'));
    }
}
