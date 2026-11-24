<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\PermissionName;
use App\Models\User;
use App\Models\WorkTimeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class GetSalariesTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary';

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryList);
        $this->employee = $this->createEmployee();
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

    public function testRequiresTheSalaryListPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function testDefaultsToTheCurrentMonth(): void
    {
        $thisMonth = $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $thisMonth->id);
        $response->assertJsonPath('data.data.0.month', '2026-05');
    }

    public function testReturnsTheRequestedMonthWithTheEmployeeAttached(): void
    {
        $march = $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?month=2026-03');

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $march->id);
        $response->assertJsonPath('data.data.0.user.id', $this->employee->id);
    }

    public function testJoinsTheWorkTimeReportOfTheSameMonth(): void
    {
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        WorkTimeReport::query()->create([
            'user_id' => $this->employee->id,
            'month' => '2026-03',
            'work_days' => 12,
            'work_minutes' => 3000,
            'work_minutes_normal' => 2400,
            'work_minutes_night' => 300,
            'work_minutes_overtime' => 200,
            'work_minutes_night_overtime' => 100,
        ]);
        // A report for a different month must not bleed into this row.
        WorkTimeReport::query()->create([
            'user_id' => $this->employee->id,
            'month' => '2026-04',
            'work_days' => 99,
            'work_minutes' => 9999,
            'work_minutes_normal' => 9999,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?month=2026-03');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.work_days', 12);
        $response->assertJsonPath('data.data.0.work_minutes', 3000);
        $response->assertJsonPath('data.data.0.work_minutes_night', 300);
    }

    public function testLeavesTheWorkTimeColumnsEmptyWhenNoReportExists(): void
    {
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?month=2026-03');

        $response->assertOk();
        $response->assertJsonPath('data.data.0.work_days', null);
        $response->assertJsonPath('data.data.0.work_minutes', null);
    }

    public function testFiltersByEmployee(): void
    {
        $other = $this->createEmployee();
        $mine = $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createFinalizedSalary($other, '2026-03', 40000);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            self::URL . "?month=2026-03&user_id={$this->employee->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $mine->id);
    }

    public function testHonoursTheRequestedPageSize(): void
    {
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createFinalizedSalary($this->createEmployee(), '2026-03', 40000);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?month=2026-03&page_size=1');

        $response->assertOk();
        $response->assertJsonPath('data.per_page', 1);
        $response->assertJsonPath('data.last_page', 2);
        $response->assertJsonCount(1, 'data.data');
    }

    public function testRejectsAMonthThatIsNotYearDashMonth(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?month=2026-3-1');

        $response->assertStatus(422);
        $this->assertArrayHasKey('month', $response->json('data'));
    }
}
