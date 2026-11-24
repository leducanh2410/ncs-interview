<?php

namespace Tests\Feature\User\Salary;

use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Enums\UserPaperStatus;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class ConfirmReceivedTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
        $this->approver = $this->createSalaryApprover();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson('/user/salary/requests/1/confirm-received')->assertUnauthorized();
    }

    public function testRequiresApprovedPapers(): void
    {
        $request = $this->createRequest();
        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $this->actingAs($this->employee, 'api')->postJson($this->confirmUrl($request))->assertForbidden();
    }

    public function testReturnsNotFoundForAnUnknownRequest(): void
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests/999999/confirm-received')
            ->assertNotFound();
    }

    public function testConfirmsAPaidRequest(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::Paid, $this->approver);

        $response = $this->actingAs($this->employee, 'api')->postJson($this->confirmUrl($request));

        $response->assertOk();
        $response->assertJsonPath('data.status', SalaryRequestStatus::Received->value);
        $request->refresh();
        $this->assertSame(SalaryRequestStatus::Received->value, $request->status);
        $this->assertSame($this->employee->id, $request->payment_confirm_id);
        $this->assertNotNull($request->payment_confirmed_at);
    }

    /**
     * Confirming receipt only means anything after the payment went out. Every earlier state,
     * and every state the request can end in, has to refuse it.
     *
     * @return array<string, array{0: SalaryRequestStatus}>
     */
    public static function unconfirmableStatusProvider(): array
    {
        return [
            'still waiting for the first approval' => [SalaryRequestStatus::WaitForApproval],
            'waiting for the accountant' => [SalaryRequestStatus::WaitForApprovalAccountant],
            'approved but not paid yet' => [SalaryRequestStatus::WaitForPayment],
            'rejected by an admin' => [SalaryRequestStatus::Rejected],
            'cancelled by the employee' => [SalaryRequestStatus::Cancelled],
            'the bank already confirmed the transfer' => [SalaryRequestStatus::ReceivedConfirmed],
            'the transfer expired and came back' => [SalaryRequestStatus::Returned],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfirmableStatusProvider')]
    public function testRefusesToConfirmAnythingThatWasNotPaid(SalaryRequestStatus $status): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, $status, $this->approver);

        $response = $this->actingAs($this->employee, 'api')->postJson($this->confirmUrl($request));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNull($request->payment_confirm_id);
        $this->assertNull($request->payment_confirmed_at);
    }

    public function testRefusesASecondConfirmation(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::Paid, $this->approver);
        $this->actingAs($this->employee, 'api')->postJson($this->confirmUrl($request))->assertOk();
        $confirmedAt = $request->fresh()->payment_confirmed_at;

        $response = $this->actingAs($this->employee, 'api')->postJson($this->confirmUrl($request));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertEquals($confirmedAt, $request->fresh()->payment_confirmed_at);
    }

    public function testAConfirmedRequestStillBlocksANewOne(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::Paid, $this->approver);
        $this->actingAs($this->employee, 'api')->postJson($this->confirmUrl($request))->assertOk();

        // Received is still an active status: the admin has yet to close the loop.
        $response = $this->actingAs($this->employee, 'api')->postJson('/user/salary/requests', ['amount' => 51000]);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::SalaryRequestExists->value);
    }

    /**
     * The route binds {salaryRequest} by bare id and there is no policy behind it, so the
     * action itself has to refuse a request that is not the caller's - otherwise a stranger
     * could close someone else's payment and be stamped on it as the confirming party.
     */
    public function testRefusesToConfirmSomeoneElsesPayment(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::Paid, $this->approver);
        $stranger = $this->createEmployee();

        $response = $this->actingAs($stranger, 'api')->postJson($this->confirmUrl($request));

        $response->assertForbidden();
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
        $request->refresh();
        $this->assertSame(SalaryRequestStatus::Paid->value, $request->status);
        $this->assertNull($request->payment_confirm_id);
        $this->assertNull($request->payment_confirmed_at);
    }

    private function createRequest(): SalaryRequest
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    private function confirmUrl(SalaryRequest $request): string
    {
        return "/user/salary/requests/{$request->id}/confirm-received";
    }
}
