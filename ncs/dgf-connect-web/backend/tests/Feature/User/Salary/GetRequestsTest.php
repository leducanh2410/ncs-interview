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

class GetRequestsTest extends TestCase
{
    use BuildsSalaryFixtures, RefreshDatabase;

    private const URL = '/user/salary/requests';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 12:00:00');

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

    public function testRequiresApprovedPapers(): void
    {
        $this->employee->update(['paper_status' => UserPaperStatus::WaitForApproval->value]);

        $this->actingAs($this->employee, 'api')->getJson(self::URL)->assertForbidden();
    }

    public function testReturnsAnEmptyPageWhenNothingWasEverRequested(): void
    {
        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['data', 'current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('data.data', []);
        $response->assertJsonPath('data.total', 0);
    }

    public function testListsTheEmployeesOwnHistoryNewestFirst(): void
    {
        // A closed request from an earlier round, then the one that is currently open.
        $first = $this->createRequest(51000);
        $this->actingAs($this->employee, 'api')
            ->postJson(self::URL . "/{$first->id}/cancel")
            ->assertOk();
        $second = $this->createRequest(52000);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonPath('data.data.0.id', $second->id);
        $response->assertJsonPath('data.data.0.status', SalaryRequestStatus::WaitForApproval->value);
        $response->assertJsonPath('data.data.1.id', $first->id);
        $response->assertJsonPath('data.data.1.status', SalaryRequestStatus::Cancelled->value);
    }

    public function testNeverLeaksAnotherEmployeesRequests(): void
    {
        $other = $this->createEmployee();
        $this->createFinalizedSalary($other, '2026-03', 50000);
        $this->createUnfinalizedSalary($other, '2026-05', 20000);
        $this->actingAs($other, 'api')
            ->postJson(self::URL, ['amount' => 51000])
            ->assertOk();
        $mine = $this->createRequest(51000);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.data.0.id', $mine->id);
    }

    public function testAppliesTheYearWindowToTheCreationDate(): void
    {
        $request = $this->createRequest(51000);

        $matching = $this->actingAs($this->employee, 'api')->getJson(self::URL . '?year=2026');
        $matching->assertOk();
        $matching->assertJsonPath('data.total', 1);
        $matching->assertJsonPath('data.data.0.id', $request->id);

        // A year the request was plainly not made in falls outside the window.
        $future = $this->actingAs($this->employee, 'api')->getJson(self::URL . '?year=2060');
        $future->assertOk();
        $future->assertJsonPath('data.total', 0);
    }

    public function testAYearTheRequestWasNotMadeInMatchesNothing(): void
    {
        $this->createRequest(51000);

        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL . '?year=2025');

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }

    public function testRejectsAYearThatIsNotAFourDigitYear(): void
    {
        $response = $this->actingAs($this->employee, 'api')->getJson(self::URL . '?year=twenty-six');

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertArrayHasKey('year', $response->json('data'));
    }

    private function createRequest(int $amount): SalaryRequest
    {
        $this->actingAs($this->employee, 'api')
            ->postJson(self::URL, ['amount' => $amount])
            ->assertOk();

        return SalaryRequest::query()->where('user_id', $this->employee->id)->latest('id')->firstOrFail();
    }
}
