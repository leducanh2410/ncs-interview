<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class GetUserSalaryTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/info';

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryCollect);
        $this->employee = $this->createEmployee();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->getJson(self::URL . '?user_id=1')->assertUnauthorized();
    }

    public function testRequiresTheSalaryCollectPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(self::URL . "?user_id={$this->employee->id}")
            ->assertForbidden();
    }

    public function testRequiresAnEmployee(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL);

        $response->assertStatus(422);
        $this->assertArrayHasKey('user_id', $response->json('data'));
    }

    public function testReturnsTheRequestableRangeAndTheUnpaidMonths(): void
    {
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . "?user_id={$this->employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.amount.min', 50000);
        $response->assertJsonPath('data.amount.max', 66000);
        $response->assertJsonPath('data.amount.min_transfer', 51000);
        $response->assertJsonPath('data.salaries.0.month', '2026-03');
    }

    /**
     * Unlike the employee's own screen, the admin view is asked for the truth right now: it
     * recalculates instead of reading the hour-long cache the employee endpoint fills.
     */
    public function testAlwaysRecalculatesInsteadOfReadingTheCache(): void
    {
        $salary = $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        // The employee opening their own screen leaves 50000 in the cache.
        $this->actingAs($this->employee, 'api')->getJson('/user/salary/info')->assertOk();
        $salary->update(['total_amount' => 90000, 'total_remaining' => 90000]);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . "?user_id={$this->employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.amount.max', 90000);

        // ...and the employee's screen now sees the recalculated figure too, because the fresh
        // run overwrote the cached one.
        $this->actingAs($this->employee, 'api')
            ->getJson('/user/salary/info')
            ->assertOk()
            ->assertJsonPath('data.amount.max', 90000);
    }

    public function testReturnsAnEmptyRangeForAnEmployeeWithNoSalary(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . "?user_id={$this->employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.amount.max', 0);
        $response->assertJsonPath('data.salaries', []);
    }

    /**
     * The endpoint takes any integer without checking it belongs to anyone, so it answers with
     * an empty range rather than a 404. Worth pinning: it means a bad id is never reported.
     */
    public function testAnswersWithAnEmptyRangeForAnIdThatIsNobody(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?user_id=999999');

        $response->assertOk();
        $response->assertJsonPath('data.amount.max', 0);
    }
}
