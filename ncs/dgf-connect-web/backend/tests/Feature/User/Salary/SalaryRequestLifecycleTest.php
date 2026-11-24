<?php

namespace Tests\Feature\User\Salary;

use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Models\Salary;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

/**
 * The whole employee-side salary round, driven only through the real endpoints: the screen
 * that shows what can be asked for, the request, the two admin approvals, the payment, and
 * the employee confirming the money arrived. The per-endpoint tests cover the branches; this
 * one exists so the states they start from are known to be reachable in sequence.
 */
class SalaryRequestLifecycleTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $employee;

    private User $approver;

    private Salary $finalized;

    private Salary $unfinalized;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
        $this->approver = $this->createSalaryApprover();
        $this->finalized = $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->unfinalized = $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testAnEmployeeGoesFromTheSalaryScreenToConfirmingThePayment(): void
    {
        // 1. The screen the employee opens before asking for anything.
        $info = $this->actingAs($this->employee, 'api')->getJson('/user/salary/info');
        $info->assertOk();
        $amount = $info->json('data.amount.min_transfer');
        $this->assertSame(51000, $amount);

        // 2. The request itself, for exactly what the screen offered.
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => $amount])
            ->assertOk();
        $request = SalaryRequest::query()->firstOrFail();

        // The screen now refuses to quote a new amount while this one is open.
        $this->actingAs($this->employee, 'api')
            ->getJson('/user/salary/info')
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::SalaryRequestExists->value);

        // 3. Manager approval, then accountant approval, then the payment.
        $this->advanceRequestTo($request, SalaryRequestStatus::Paid, $this->approver);
        $request->refresh();
        $this->assertNotNull($request->approved_at);
        $this->assertNotNull($request->accountant_approved_at);
        $this->assertNotNull($request->paid_at);
        $this->assertSame($this->approver->id, $request->approver_id);
        $this->assertSame($this->approver->id, $request->accountant_approver_id);

        // 4. The employee confirms the money arrived.
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$request->id}/confirm-received")
            ->assertOk();

        $request->refresh();
        $this->assertSame(SalaryRequestStatus::Received->value, $request->status);
        $this->assertSame($this->employee->id, $request->payment_confirm_id);

        // 5. The round is visible in the employee's own history.
        $history = $this->actingAs($this->employee, 'api')->getJson('/user/salary/requests');
        $history->assertOk();
        $history->assertJsonPath('data.total', 1);
        $history->assertJsonPath('data.data.0.status', SalaryRequestStatus::Received->value);
    }

    public function testASecondRoundIgnoresTheLeftoversOfARejectedOne(): void
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();
        $rejected = SalaryRequest::query()->firstOrFail();
        $this->advanceRequestTo($rejected, SalaryRequestStatus::Rejected, $this->approver);

        // Same months, same balances: a closed request must leave the requestable range alone.
        $info = $this->actingAs($this->employee, 'api')->getJson('/user/salary/info');
        $info->assertOk();
        $info->assertJsonPath('data.amount.min', 50000);
        $info->assertJsonPath('data.amount.max', 66000);

        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 65000])
            ->assertOk();

        $new = SalaryRequest::query()->latest('id')->firstOrFail();
        $this->assertNotSame($rejected->id, $new->id);

        // The new round drew its own details from the same salary rows...
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $new->id,
            'salary_id' => $this->finalized->id,
            'amount' => 50000,
        ]);
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $new->id,
            'salary_id' => $this->unfinalized->id,
            'amount' => 15000,
        ]);
        // ...without disturbing what the rejected round recorded.
        $this->assertDatabaseHas('salary_request_details', [
            'salary_request_id' => $rejected->id,
            'salary_id' => $this->unfinalized->id,
            'amount' => 1000,
        ]);

        $history = $this->actingAs($this->employee, 'api')->getJson('/user/salary/requests');
        $history->assertJsonPath('data.total', 2);
    }
}
