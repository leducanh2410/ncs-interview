<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Models\Salary;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * The admin raises a request on an employee's behalf. It shares the writing half with the
 * employee endpoint but not the gate: there is no min/max range check here, and a cash
 * request neither owes the transfer fee nor has to land on a 1000 yen step.
 */
class CreateSalaryRequestTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/requests';

    private User $admin;

    private User $employee;

    private Salary $finalized;

    private Salary $unfinalized;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryRequestCreate);
        $this->employee = $this->createEmployee();
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
        $this->postJson(self::URL, $this->payload())->assertUnauthorized();
    }

    public function testRequiresTheSalaryRequestCreatePermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson(self::URL, $this->payload())
            ->assertForbidden();
    }

    public function testCreatesATransferRequestSplitAcrossMonths(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.user_id', $this->employee->id);
        $response->assertJsonPath('data.amount', 51000);
        $response->assertJsonPath('data.type', SalaryRequestType::Transfer->value);
        // It enters the queue at the very first stage, exactly like an employee's own request.
        $response->assertJsonPath('data.status', SalaryRequestStatus::WaitForApproval->value);

        $request = SalaryRequest::query()->firstOrFail();
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $this->unfinalized->id,
            'amount' => 1000,
            'has_fee' => true,
        ]);
    }

    public function testACashRequestCarriesNoTransferFee(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(['amount' => 50000, 'type' => SalaryRequestType::Cash->value])
        );

        $response->assertOk();
        $request = SalaryRequest::query()->firstOrFail();
        // The whole amount comes out of the settled month and nothing is set aside for a fee,
        // which is why 50000 works here and is refused as a transfer.
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $request->id,
            'salary_id' => $this->finalized->id,
            'amount' => 50000,
            'has_fee' => false,
        ]);
        $this->assertDatabaseCount('salary_request_details', 1);
    }

    public function testTheSameAmountIsRefusedAsATransferBecauseOfTheFee(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(['amount' => 66000, 'type' => SalaryRequestType::Transfer->value])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertArrayHasKey('amount', $response->json('data'));
        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testAnAdminMayGoBelowTheRangeTheEmployeeIsOffered(): void
    {
        // The employee's own endpoint refuses anything under 50000 here; the admin path has no
        // such floor and only has to be payable.
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 1000])
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::SalaryRequestInvalidAmount->value);

        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload(['amount' => 1000]));

        $response->assertOk();
        $response->assertJsonPath('data.amount', 1000);
    }

    public function testACashRequestNeedNotLandOnAThousandYenStep(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(['amount' => 1500, 'type' => SalaryRequestType::Cash->value])
        );

        $response->assertOk();
        $response->assertJsonPath('data.amount', 1500);
    }

    public function testATransferRequestMustLandOnAThousandYenStep(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload(['amount' => 1500]));

        $response->assertStatus(422);
        $this->assertArrayHasKey('amount', $response->json('data'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'no employee' => [['user_id' => null], 'user_id'],
            'employee is not an id' => [['user_id' => 'me'], 'user_id'],
            'no amount' => [['amount' => null], 'amount'],
            'negative amount' => [['amount' => -1000], 'amount'],
            'no type' => [['type' => null], 'type'],
            'unknown type' => [['type' => 'cheque'], 'type'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloadProvider')]
    public function testRejectsAnInvalidPayload(array $overrides, string $expectedField): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload($overrides));

        $response->assertStatus(422);
        $this->assertArrayHasKey($expectedField, $response->json('data'));
        $this->assertDatabaseCount('salary_requests', 0);
    }

    public function testRejectsAnEmployeeWithNothingToDrawFrom(): void
    {
        $stranger = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            self::URL,
            $this->payload(['user_id' => $stranger->id])
        );

        // No unpaid salary at all, so no month can carry the amount.
        $response->assertStatus(422);
        $this->assertArrayHasKey('amount', $response->json('data'));
    }

    public function testRefusesASecondRequestWhileTheEmployeeAlreadyHasAnActiveOne(): void
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        $response = $this->actingAs($this->admin, 'api')->postJson(self::URL, $this->payload(['amount' => 52000]));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestExists->value);
        $this->assertDatabaseCount('salary_requests', 1);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'user_id' => $this->employee->id,
            'amount' => 51000,
            'type' => SalaryRequestType::Transfer->value,
        ];
    }
}
