<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalaryFixtures;
use Tests\TestCase;

class GetRequestDetailTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

        $this->admin = $this->createSalaryAdmin(PermissionName::SalaryRequestList);
        $this->employee = $this->createEmployee();
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
        $this->getJson('/manage/salary/requests/1')->assertUnauthorized();
    }

    public function testRequiresTheSalaryRequestListPermission(): void
    {
        $request = $this->createRequest();

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/manage/salary/requests/{$request->id}")
            ->assertForbidden();
    }

    public function testReturnsNotFoundForAnUnknownRequest(): void
    {
        $this->actingAs($this->admin, 'api')->getJson('/manage/salary/requests/999999')->assertNotFound();
    }

    public function testReturnsTheRequestWithEverythingTheReviewScreenNeeds(): void
    {
        $request = $this->createRequest();
        $approver = $this->createSalaryApprover();
        $this->advanceRequestTo($request, SalaryRequestStatus::WaitForPayment, $approver);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/salary/requests/{$request->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $request->id);
        $response->assertJsonPath('data.status', SalaryRequestStatus::WaitForPayment->value);
        $response->assertJsonPath('data.user.id', $this->employee->id);
        $response->assertJsonPath('data.approver.id', $approver->id);
        $response->assertJsonPath('data.accountant_approver.id', $approver->id);
        // Each month the request draws from, with the salary row behind it. The relation is
        // unordered, so assert on the set rather than on positions.
        $details = $response->json('data.details');
        $this->assertCount(2, $details);
        $this->assertEqualsCanonicalizing(['2026-03', '2026-05'], collect($details)->pluck('salary.month')->all());
    }

    public function testLeavesTheApproverFieldsNullBeforeAnyoneDecided(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/salary/requests/{$request->id}");

        $response->assertOk();
        $response->assertJsonPath('data.approver', null);
        $response->assertJsonPath('data.accountant_approver', null);
    }

    private function createRequest(): SalaryRequest
    {
        $this->actingAs($this->employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }
}
