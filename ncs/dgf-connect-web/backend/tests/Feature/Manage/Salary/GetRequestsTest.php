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

class GetRequestsTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/manage/salary/requests';

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
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function testRequiresTheSalaryRequestListPermission(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson(self::URL)
            ->assertForbidden();
    }

    public function testReturnsAPaginatedPageWithTheRequestingEmployeeAttached(): void
    {
        $request = $this->createRequestFor($this->employee);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['data', 'current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $request->id);
        $response->assertJsonPath('data.data.0.user.id', $this->employee->id);
    }

    public function testListsEveryEmployeeByDefault(): void
    {
        $other = $this->createEmployee();
        $this->createFinalizedSalary($other, '2026-03', 50000);
        $this->createUnfinalizedSalary($other, '2026-05', 20000);
        $this->createRequestFor($this->employee);
        $this->createRequestFor($other);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
    }

    public function testFiltersByEmployee(): void
    {
        $other = $this->createEmployee();
        $this->createFinalizedSalary($other, '2026-03', 50000);
        $this->createUnfinalizedSalary($other, '2026-05', 20000);
        $mine = $this->createRequestFor($this->employee);
        $this->createRequestFor($other);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . "?user_id={$this->employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $mine->id);
    }

    public function testFiltersByStatus(): void
    {
        $cancelled = $this->createRequestFor($this->employee);
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$cancelled->id}/cancel")
            ->assertOk();
        $open = $this->createRequestFor($this->employee);

        $waiting = $this->actingAs($this->admin, 'api')->getJson(
            self::URL . '?status=' . SalaryRequestStatus::WaitForApproval->value
        );
        $waiting->assertOk();
        $waiting->assertJsonPath('data.total', 1);
        $waiting->assertJsonPath('data.data.0.id', $open->id);

        $closed = $this->actingAs($this->admin, 'api')->getJson(
            self::URL . '?status=' . SalaryRequestStatus::Cancelled->value
        );
        $closed->assertOk();
        $closed->assertJsonPath('data.total', 1);
        $closed->assertJsonPath('data.data.0.id', $cancelled->id);
    }

    public function testTheAllKeywordLiftsTheStatusFilter(): void
    {
        $cancelled = $this->createRequestFor($this->employee);
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$cancelled->id}/cancel")
            ->assertOk();
        $this->createRequestFor($this->employee);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?status=all');

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
    }

    public function testHonoursTheRequestedPageSize(): void
    {
        $first = $this->createRequestFor($this->employee);
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/salary/requests/{$first->id}/cancel")
            ->assertOk();
        $this->createRequestFor($this->employee);

        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?page_size=1');

        $response->assertOk();
        $response->assertJsonPath('data.per_page', 1);
        $response->assertJsonPath('data.last_page', 2);
        $response->assertJsonCount(1, 'data.data');
    }

    public function testRejectsAPageSizeThatIsNotAnInteger(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(self::URL . '?page_size=lots');

        $response->assertStatus(422);
        $this->assertArrayHasKey('page_size', $response->json('data'));
    }

    private function createRequestFor(User $employee): SalaryRequest
    {
        $this->actingAs($employee, 'api')
            ->postJson('/user/salary/requests', ['amount' => 51000])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $employee->id)->latest('id')->firstOrFail();
    }
}
