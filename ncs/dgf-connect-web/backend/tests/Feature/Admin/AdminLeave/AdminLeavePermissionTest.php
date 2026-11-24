<?php

namespace Tests\Feature\Admin\AdminLeave;

use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use App\Enums\PermissionName;
use App\Models\AdminLeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class AdminLeavePermissionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private User $requester;

    private User $otherAdmin;

    private AdminLeaveRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->requester = User::factory()->create();
        $this->otherAdmin = User::factory()->create();

        $this->giveGlobalPermission($this->manager, PermissionName::AdminLeaveManage);
        $this->giveGlobalPermission($this->requester, PermissionName::ManagerAuth);
        $this->giveGlobalPermission($this->otherAdmin, PermissionName::ManagerAuth);

        $this->request = AdminLeaveRequest::query()->create([
            'requester_id' => $this->requester->id,
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'leave_days' => 5,
            'reason' => 'Family trip',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function managerEndpointProvider(): array
    {
        return [
            'index' => ['getJson', '/admin/admin-leave-requests', []],
            'approve' => ['postJson', '/admin/admin-leave-requests/{id}/approve', []],
            'reject' => ['postJson', '/admin/admin-leave-requests/{id}/reject', ['reject_reason' => 'No']],
        ];
    }

    #[DataProvider('managerEndpointProvider')]
    public function testManagerEndpointsAreForbiddenWithoutTheLeavePermission(
        string $method,
        string $url,
        array $payload
    ): void {
        $this->actingAs($this->otherAdmin, 'api')
            ->{$method}(str_replace('{id}', (string) $this->request->id, $url), $payload)
            ->assertForbidden();
    }

    public function testCreatingARequestIsForbiddenWithoutManagerAuth(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'api')
            ->postJson('/admin/admin-leave-requests', [
                'type' => AdminLeaveType::ExpenseClaim->value,
                'reason' => 'Taxi fare',
            ])
            ->assertForbidden();

        $this->assertSame(1, AdminLeaveRequest::query()->count());
    }

    public function testListingOwnRequestsIsForbiddenWithoutManagerAuth(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'api')->getJson('/admin/admin-leave-requests/mine')->assertForbidden();
    }

    public function testThePreviewEndpointIsForbiddenWithoutManagerAuth(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'api')
            ->postJson('/admin/admin-leave-requests/preview-days', [
                'start_date' => '2027-09-06',
                'end_date' => '2027-09-10',
            ])
            ->assertForbidden();
    }

    public function testTheRequesterCanReadTheirOwnDetail(): void
    {
        $this->actingAs($this->requester, 'api')
            ->getJson('/admin/admin-leave-requests/' . $this->request->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->request->id);
    }

    public function testAnUnrelatedAdminCannotReadSomeoneElsesDetail(): void
    {
        $this->actingAs($this->otherAdmin, 'api')
            ->getJson('/admin/admin-leave-requests/' . $this->request->id)
            ->assertForbidden();
    }

    public function testALeaveManagerCanReadAnyDetail(): void
    {
        $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests/' . $this->request->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->request->id);
    }

    public function testTheOwnListOnlyReturnsTheCallersRequests(): void
    {
        AdminLeaveRequest::query()->create([
            'requester_id' => $this->otherAdmin->id,
            'type' => AdminLeaveType::DayOff->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-06',
            'leave_days' => 1,
            'reason' => 'Someone else',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);

        $ids = array_column(
            $this->actingAs($this->requester, 'api')
                ->getJson('/admin/admin-leave-requests/mine')
                ->assertOk()
                ->json('data.data'),
            'id'
        );

        $this->assertSame([$this->request->id], $ids);
    }

    public function testUnauthenticatedCallsAreRejected(): void
    {
        $this->getJson('/admin/admin-leave-requests')->assertUnauthorized();
        $this->getJson('/admin/admin-leave-requests/mine')->assertUnauthorized();
    }

    public function testADecisionByTheRequesterThemselvesIsForbiddenEvenWithTheLeavePermission(): void
    {
        // The realistic shape of the self-approval case: someone who genuinely reviews requests
        // submitting one of their own.
        $selfReviewer = User::factory()->create();
        $this->giveGlobalPermission($selfReviewer, PermissionName::AdminLeaveManage);
        $own = AdminLeaveRequest::query()->create([
            'requester_id' => $selfReviewer->id,
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-06',
            'leave_days' => 1,
            'reason' => 'My own leave',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);

        $this->actingAs($selfReviewer, 'api')
            ->postJson('/admin/admin-leave-requests/' . $own->id . '/approve')
            ->assertForbidden();
        $this->actingAs($selfReviewer, 'api')
            ->postJson('/admin/admin-leave-requests/' . $own->id . '/reject', ['reject_reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(AdminLeaveRequestStatus::Pending, $own->fresh()->status);
    }
}
