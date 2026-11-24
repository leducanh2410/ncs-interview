<?php

namespace Tests\Feature\User\Salary;

use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Enums\UserPaperStatus;
use App\Models\Salary;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class CreateRequestTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/user/salary/requests';

    private User $employee;

    private Salary $finalized;

    private Salary $unfinalized;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
        // Requestable range for this pair: min 50000, max 66000 (see GetSalaryInfoTest).
        $this->finalized = $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->unfinalized = $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson(self::URL, ['amount' => 51000])->assertUnauthorized();
    }

    public function testRequiresApprovedPapers(): void
    {
        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $this->actingAs($this->employee, 'api')
            ->postJson(self::URL, ['amount' => 51000])
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidAmountProvider(): array
    {
        return [
            'missing' => [null],
            'not a number' => ['abc'],
            'below the 1000 floor' => [500],
            'not a multiple of 1000' => [51500],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmountProvider')]
    public function testRejectsAnAmountThatFailsValidation(mixed $amount): void
    {
        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => $amount]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertArrayHasKey('amount', $response->json('data'));
        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testCreatesTheRequestAndSplitsItAcrossMonths(): void
    {
        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 51000]);

        $response->assertOk();
        $response->assertJsonPath('data.amount', 51000);
        $response->assertJsonPath('data.status', SalaryRequestStatus::WaitForApproval->value);
        $response->assertJsonPath('data.type', SalaryRequestType::Transfer->value);
        // max_amount records the whole unpaid balance at request time, not what was asked for.
        $response->assertJsonPath('data.max_amount', 66000);

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertSame($this->employee->id, $request->user_id);

        // The settled month is drained first; the remainder comes out of the month in
        // progress, which is therefore the month that carries the transfer fee.
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $this->finalized->id,
            'amount' => 50000,
            'has_fee' => false,
        ]);
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $this->unfinalized->id,
            'amount' => 1000,
            'has_fee' => true,
        ]);
    }

    public function testAcceptsTheTopOfTheTransferableRange(): void
    {
        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 65000]);

        $response->assertOk();
        $this->assertDatabaseHas('salary_request_details', [
            'salary_id' => $this->unfinalized->id,
            'amount' => 15000,
            'has_fee' => true,
        ]);
    }

    public function testRejectsAnAmountBelowTheRequestableMinimum(): void
    {
        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 49000]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestInvalidAmount->value);
        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testRejectsAnAmountAboveTheRequestableMaximum(): void
    {
        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 67000]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestInvalidAmount->value);
        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testRejectsAnAmountAboveTheConfiguredTransferCeiling(): void
    {
        config(['salary.request.max_transfer_amount' => 60000]);

        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 65000]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestInvalidAmount->value);
        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testRejectsAnAmountThatLeavesNothingToPayTheTransferFeeFrom(): void
    {
        // 66000 is the whole unpaid balance: it passes the min/max gate, but then there is no
        // yen left in the last month to cover the 330 transfer fee.
        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 66000]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertArrayHasKey('amount', $response->json('data'));
        $this->assertDatabaseCount('salary_requests', 0);
        $this->assertDatabaseCount('salary_request_details', 0);
    }

    public function testRejectsASecondRequestWhileTheFirstIsStillActive(): void
    {
        $this->actingAs($this->employee, 'api')
            ->postJson(self::URL, ['amount' => 51000])
            ->assertOk();

        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 52000]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestExists->value);
        $this->assertDatabaseCount('salary_requests', 1);
    }

    public function testAllowsANewRequestAfterTheEarlierOneWasCancelled(): void
    {
        $this->actingAs($this->employee, 'api')
            ->postJson(self::URL, ['amount' => 51000])
            ->assertOk();
        $first = SalaryRequest::query()->firstOrFail();
        $this->actingAs($this->employee, 'api')
            ->postJson(self::URL . "/{$first->id}/cancel")
            ->assertOk();

        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 52000]);

        $response->assertOk();
        $this->assertDatabaseCount('salary_requests', 2);
    }

    public function testAnotherEmployeesActiveRequestDoesNotBlockThisOne(): void
    {
        $other = $this->createEmployee();
        $this->createFinalizedSalary($other, '2026-03', 50000);
        $this->createUnfinalizedSalary($other, '2026-05', 20000);
        $this->actingAs($other, 'api')
            ->postJson(self::URL, ['amount' => 51000])
            ->assertOk();

        $response = $this->actingAs($this->employee, 'api')->postJson(self::URL, ['amount' => 51000]);

        $response->assertOk();
        $this->assertDatabaseCount('salary_requests', 2);
    }
}
