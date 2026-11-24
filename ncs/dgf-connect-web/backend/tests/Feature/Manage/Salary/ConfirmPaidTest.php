<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class ConfirmPaidTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $employee;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
        $this->payer = $this->createSalaryAdmin(PermissionName::SalaryRequestConfirmPaid);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson('/manage/salary/requests/1/confirm-paid')->assertUnauthorized();
    }

    public function testRequiresTheConfirmPaidPermission(): void
    {
        $request = $this->createRequest();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson($this->url($request))
            ->assertForbidden();
    }

    public function testMarksAnApprovedRequestAsPaid(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::WaitForPayment, $this->createSalaryApprover());

        $response = $this->actingAs($this->payer, 'api')->postJson($this->url($request));

        $response->assertOk();
        $response->assertJsonPath('data.status', SalaryRequestStatus::Paid->value);
        $request->refresh();
        $this->assertNotNull($request->paid_at);
        // Marking the money as sent is not the same as the employee confirming it arrived.
        $this->assertNull($request->payment_confirm_id);
    }

    /**
     * @return array<string, array{0: SalaryRequestStatus}>
     */
    public static function unpayableStatusProvider(): array
    {
        return [
            'nobody approved it yet' => [SalaryRequestStatus::WaitForApproval],
            'the accountant has not signed off' => [SalaryRequestStatus::WaitForApprovalAccountant],
            'already paid' => [SalaryRequestStatus::Paid],
            'rejected' => [SalaryRequestStatus::Rejected],
            'cancelled by the employee' => [SalaryRequestStatus::Cancelled],
            'the employee confirmed the money arrived' => [SalaryRequestStatus::Received],
            'the bank confirmed the transfer' => [SalaryRequestStatus::ReceivedConfirmed],
            'the transfer expired and came back' => [SalaryRequestStatus::Returned],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unpayableStatusProvider')]
    public function testRefusesToPayARequestThatIsNotWaitingForPayment(SalaryRequestStatus $status): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, $status, $this->createSalaryApprover());
        $paidAt = $request->fresh()->paid_at;

        $response = $this->actingAs($this->payer, 'api')->postJson($this->url($request));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertEquals($paidAt, $request->paid_at);
    }

    public function testReturnsNotFoundForAnUnknownRequest(): void
    {
        $this->actingAs($this->payer, 'api')->postJson('/manage/salary/requests/999999/confirm-paid')->assertNotFound();
    }

    private function createRequest(): SalaryRequest
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    private function url(SalaryRequest $request): string
    {
        return "/manage/salary/requests/{$request->id}/confirm-paid";
    }
}
