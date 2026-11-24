<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class RejectRequestTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $employee;

    private User $rejecter;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->employee = $this->createEmployee();
        $this->createFinalizedSalary($this->employee, '2026-03', 50000);
        $this->createUnfinalizedSalary($this->employee, '2026-05', 20000);
        $this->rejecter = $this->createSalaryAdmin(PermissionName::SalaryRequestReject);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postJson('/manage/salary/requests/1/reject', ['reason' => 'No'])->assertUnauthorized();
    }

    public function testRequiresTheRejectPermission(): void
    {
        $request = $this->createRequest();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson($this->url($request), ['reason' => 'No'])
            ->assertForbidden();
    }

    /**
     * Approving is not the same authority as rejecting: an approver must not be able to close
     * a request by rejecting it.
     */
    public function testTheApprovePermissionDoesNotGrantRejection(): void
    {
        $request = $this->createRequest();
        $approver = $this->createSalaryAdmin(PermissionName::SalaryRequestApprove);

        $this->actingAs($approver, 'api')
            ->postJson($this->url($request), ['reason' => 'No'])
            ->assertForbidden();
    }

    public function testRejectsARequestAndRecordsWhoDecidedAndWhy(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->rejecter, 'api')->postJson($this->url($request), [
            'reason' => 'Requested too early this month',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', SalaryRequestStatus::Rejected->value);
        $request->refresh();
        $this->assertSame('Requested too early this month', $request->reject_reason);
        $this->assertSame($this->rejecter->id, $request->approver_id);
        $this->assertNotNull($request->rejected_at);
    }

    public function testARejectedRequestNoLongerBlocksANewOne(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->rejecter, 'api')
            ->postJson($this->url($request), ['reason' => 'No'])
            ->assertOk();

        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 52000])
            ->assertOk();
    }

    public function testRequiresAReason(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->rejecter, 'api')->postJson($this->url($request), []);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertArrayHasKey('reason', $response->json('data'));
        $this->assertSame(SalaryRequestStatus::WaitForApproval->value, $request->fresh()->status);
    }

    public function testRefusesAReasonLongerThanTheColumnHolds(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->rejecter, 'api')->postJson($this->url($request), [
            'reason' => Str::repeat('a', 256),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('reason', $response->json('data'));
    }

    /**
     * Rejection is only open at the very first stage. Once the request moved on - in either
     * direction - it has to be handled by whoever owns that stage.
     *
     * @return array<string, array{0: SalaryRequestStatus}>
     */
    public static function unrejectableStatusProvider(): array
    {
        return [
            'already with the accountant' => [SalaryRequestStatus::WaitForApprovalAccountant],
            'released for payment' => [SalaryRequestStatus::WaitForPayment],
            'already paid' => [SalaryRequestStatus::Paid],
            'already rejected' => [SalaryRequestStatus::Rejected],
            'cancelled by the employee' => [SalaryRequestStatus::Cancelled],
            'the employee confirmed the money arrived' => [SalaryRequestStatus::Received],
            'the bank confirmed the transfer' => [SalaryRequestStatus::ReceivedConfirmed],
            'the transfer expired and came back' => [SalaryRequestStatus::Returned],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrejectableStatusProvider')]
    public function testRefusesToRejectOnceTheRequestLeftTheFirstStage(SalaryRequestStatus $status): void
    {
        $request = $this->createRequest();
        $this->advanceRequestTo($request, $status, $this->createSalaryApprover());

        $response = $this->actingAs($this->rejecter, 'api')->postJson($this->url($request), ['reason' => 'Too late']);

        $response->assertStatus(400);
        $response->assertJsonPath('error', ErrorCode::BadRequest->value);
        $this->assertSame($status->value, $request->fresh()->status);
    }

    public function testReturnsNotFoundForAnUnknownRequest(): void
    {
        $this->actingAs($this->rejecter, 'api')
            ->postJson('/manage/salary/requests/999999/reject', ['reason' => 'No'])
            ->assertNotFound();
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
        return "/manage/salary/requests/{$request->id}/reject";
    }
}
