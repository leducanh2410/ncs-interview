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

class CancelRequestTest extends TestCase
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
        // The id never gets resolved: auth runs before the route binding.
        $this->postJson('/user/salary/requests/1/cancel')->assertUnauthorized();
    }

    public function testRequiresApprovedPapers(): void
    {
        $request = $this->createRequest();
        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request))->assertForbidden();
    }

    public function testReturnsNotFoundForAnUnknownRequest(): void
    {
        $this->actingAs($this->employee, 'api')->postJson('/user/salary/requests/999999/cancel')->assertNotFound();
    }

    public function testCancelsARequestThatIsStillWaitingForApproval(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request));

        $response->assertOk();
        $response->assertJsonPath('data.status', SalaryRequestStatus::Cancelled->value);
        $request->refresh();
        $this->assertSame(SalaryRequestStatus::Cancelled->value, $request->status);
        $this->assertNotNull($request->cancelled_at);
    }

    public function testCancelsARequestTheFirstApproverAlreadyPassedOn(): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, SalaryRequestStatus::WaitForApprovalAccountant, $this->approver);

        $response = $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request));

        $response->assertOk();
        $this->assertSame(SalaryRequestStatus::Cancelled->value, $request->fresh()->status);
    }

    /**
     * Every state the request can be in when a late cancel arrives. The two approval stages
     * are the only ones the employee may still pull out of; once the money is on its way, or
     * the request is already closed, the cancel must bounce.
     *
     * @return array<string, array{0: SalaryRequestStatus}>
     */
    public static function uncancellableStatusProvider(): array
    {
        return [
            'approved by the accountant, waiting for payment' => [SalaryRequestStatus::WaitForPayment],
            'already paid out' => [SalaryRequestStatus::Paid],
            'rejected by an admin' => [SalaryRequestStatus::Rejected],
            'the employee confirmed the money arrived' => [SalaryRequestStatus::Received],
            'the bank confirmed the transfer' => [SalaryRequestStatus::ReceivedConfirmed],
            'the transfer expired and came back' => [SalaryRequestStatus::Returned],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncancellableStatusProvider')]
    public function testRefusesToCancelOnceTheRequestLeftTheApprovalStages(SalaryRequestStatus $status): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, $status, $this->approver);

        $response = $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertSame($status->value, $request->fresh()->status);
    }

    public function testRefusesASecondCancellation(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request))->assertOk();
        $cancelledAt = $request->fresh()->cancelled_at;

        $response = $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertEquals($cancelledAt, $request->fresh()->cancelled_at);
    }

    /**
     * The route binds {salaryRequest} by bare id and there is no policy behind it, so the
     * action itself has to refuse a request that is not the caller's - the way
     * ScheduleService::confirmSchedule does for a shift.
     */
    public function testRefusesToCancelSomeoneElsesRequest(): void
    {
        $request = $this->createRequest();
        $stranger = $this->createEmployee();

        $response = $this->actingAs($stranger, 'api')->postJson($this->cancelUrl($request));

        $response->assertForbidden();
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertSame(SalaryRequestStatus::WaitForApproval->value, $request->fresh()->status);
        $this->assertNull($request->fresh()->cancelled_at);
    }

    /**
     * Ownership is decided before the status, so a stranger cannot tell an already-cancelled
     * request apart from one that is still open.
     */
    public function testTellsAStrangerNothingAboutTheStateOfTheRequest(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->employee, 'api')->postJson($this->cancelUrl($request))->assertOk();
        $stranger = $this->createEmployee();

        $response = $this->actingAs($stranger, 'api')->postJson($this->cancelUrl($request));

        // 403 rather than the 400 the owner would get for cancelling twice.
        $response->assertForbidden();
    }

    private function createRequest(): SalaryRequest
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }

    private function cancelUrl(SalaryRequest $request): string
    {
        return "/user/salary/requests/{$request->id}/cancel";
    }
}
