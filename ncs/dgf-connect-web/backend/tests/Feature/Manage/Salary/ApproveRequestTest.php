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

/**
 * Approval is two-staged and the two stages are gated by different permissions, but a single
 * endpoint serves both: which stage runs is decided by the status the request is already in.
 */
class ApproveRequestTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $employee;

    private User $manager;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
        $this->manager = $this->createSalaryAdmin(PermissionName::SalaryRequestApprove);
        $this->accountant = $this->createSalaryAdmin(PermissionName::SalaryRequestApproveAccountant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson('/manage/salary/requests/1/approve')->assertUnauthorized();
    }

    public function testRequiresOneOfTheTwoApprovalPermissions(): void
    {
        $request = $this->createRequest();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson($this->url($request))
            ->assertForbidden();
    }

    public function testTheFirstApprovalHandsTheRequestToTheAccountant(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url($request));

        $response->assertOk();
        $response->assertJsonPath('data.status', SalaryRequestStatus::WaitForApprovalAccountant->value);
        $request->refresh();
        $this->assertSame($this->manager->id, $request->approver_id);
        $this->assertNotNull($request->approved_at);
        $this->assertNull($request->accountant_approver_id);
    }

    public function testTheAccountantApprovalReleasesTheRequestForPayment(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->manager, 'api')->postJson($this->url($request))->assertOk();

        $response = $this->actingAs($this->accountant, 'api')->postJson($this->url($request));

        $response->assertOk();
        $response->assertJsonPath('data.status', SalaryRequestStatus::WaitForPayment->value);
        $request->refresh();
        $this->assertSame($this->accountant->id, $request->accountant_approver_id);
        $this->assertNotNull($request->accountant_approved_at);
        // The first approver is left as recorded.
        $this->assertSame($this->manager->id, $request->approver_id);
    }

    public function testAFirstStageApproverCannotAlsoSignOffAsTheAccountant(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->manager, 'api')->postJson($this->url($request))->assertOk();

        $response = $this->actingAs($this->manager, 'api')->postJson($this->url($request));

        $response->assertForbidden();
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertSame(SalaryRequestStatus::WaitForApprovalAccountant->value, $request->fresh()->status);
    }

    public function testAnAccountantCannotStandInForTheFirstApproval(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->accountant, 'api')->postJson($this->url($request));

        $response->assertForbidden();
        $response->assertJsonPath('error', ErrorCode::Forbidden->value);
        $this->assertSame(SalaryRequestStatus::WaitForApproval->value, $request->fresh()->status);
    }

    /**
     * @return array<string, array{0: SalaryRequestStatus}>
     */
    public static function undecidableStatusProvider(): array
    {
        return [
            'already released for payment' => [SalaryRequestStatus::WaitForPayment],
            'already paid' => [SalaryRequestStatus::Paid],
            'already rejected' => [SalaryRequestStatus::Rejected],
            'cancelled by the employee' => [SalaryRequestStatus::Cancelled],
            'the employee confirmed the money arrived' => [SalaryRequestStatus::Received],
            'the bank confirmed the transfer' => [SalaryRequestStatus::ReceivedConfirmed],
            'the transfer expired and came back' => [SalaryRequestStatus::Returned],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('undecidableStatusProvider')]
    public function testRefusesToApproveARequestThatIsNoLongerWaiting(SalaryRequestStatus $status): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, $status, $this->createSalaryApprover());
        $approver = $this->createSalaryApprover();

        $response = $this->actingAs($approver, 'api')->postJson($this->url($request));

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertSame($status->value, $request->fresh()->status);
    }

    public function testReturnsNotFoundForAnUnknownRequest(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/manage/salary/requests/999999/approve')->assertNotFound();
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
        return "/manage/salary/requests/{$request->id}/approve";
    }
}
