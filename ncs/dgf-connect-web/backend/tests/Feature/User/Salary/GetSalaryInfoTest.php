<?php

namespace Tests\Feature\User\Salary;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\ErrorCode;
use App\Enums\UserPaperStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class GetSalaryInfoTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/user/salary/info';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-month, so the current month is only partially covered by the recalculation
        // window and an unfinalized row for it survives the sweep.
        Carbon::setTestNow('2026-05-10 12:00:00');

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

    public function testRequiresApprovedPapers(): void
    {
        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $this->actingAs($this->employee, 'api')->getJson(self::URL)->assertForbidden();
    }

    public function testReturnsAnEmptyRangeWhenNothingIsUnpaid(): void
    {
        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.amount.min', 0);
        $response->assertJsonPath('data.amount.max', 0);
        $response->assertJsonPath('data.amount.unfinalized', 0);
        $response->assertJsonPath('data.amount.min_transfer', 0);
        $response->assertJsonPath('data.amount.max_transfer', 0);
        $response->assertJsonPath('data.salaries', []);
    }

    public function testReportsFinalizedMonthsInFullAndUnfinalizedMonthsAtEightyPercent(): void
    {
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        // min/max are the raw balance: what the settled month is worth, plus 80% of the month
        // still in progress. The bounds a request has to land between are min_transfer and
        // max_transfer, asserted just below.
        $response->assertJsonPath('data.amount.min', 50000);
        $response->assertJsonPath('data.amount.max', 66000);
        $response->assertJsonPath('data.amount.unfinalized', 16000);
        // The transferable bounds are the nearest 1000-multiples that still leave the 330 yen
        // transfer fee payable out of the last month touched.
        $response->assertJsonPath('data.amount.min_transfer', 51000);
        $response->assertJsonPath('data.amount.max_transfer', 65000);

        // Finalized months are listed first, then by month ascending.
        $response->assertJsonPath('data.salaries.0.month', '2026-03');
        $response->assertJsonPath('data.salaries.1.month', '2026-05');
    }

    public function testCapsTheTransferableMaximumAtTheConfiguredCeiling(): void
    {
        config(['salary.request.max_transfer_amount' => 20000]);
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        // The ceiling bounds what may be requested, not what the employee is owed, so it is
        // max_transfer that moves while max keeps reporting the balance.
        $response->assertJsonPath('data.amount.max_transfer', 20000);
        $response->assertJsonPath('data.amount.max', 50000);
    }

    public function testRejectsWhileAnEarlierRequestIsStillActive(): void
    {
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);

        // Reach the "has an active request" state the way the employee does.
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestExists->value);
    }

    public function testServesTheCachedRangeOnASecondCall(): void
    {
        $salary = $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $this->actingAs($this->employee, 'api')->getJson(self::URL)->assertOk();
        $this->assertNotNull(Cache::get(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id));

        // A change written straight to the row (no recalculation, so no cache invalidation)
        // must not show up until the hour-long cache expires.
        $salary->update(['total_amount' => 90000, 'total_remaining' => 90000]);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.amount.max', 50000);
        // The salary list itself is always read fresh, only the computed range is cached.
        $response->assertJsonPath('data.salaries.0.total_amount', 90000);
    }

    public function testRecomputesTheRangeOnceTheCacheIsCleared(): void
    {
        $salary = $this->createFinalizedSalary($this->employee, '2026-03', 50000);

        $this->actingAs($this->employee, 'api')->getJson(self::URL)->assertOk();

        $salary->update(['total_amount' => 90000, 'total_remaining' => 90000]);
        Cache::forget(CollectUserSalaryAction::CACHE_KEY_PREFIX . $this->employee->id);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.amount.max', 90000);
    }
}
