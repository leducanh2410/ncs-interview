<?php

namespace Tests\Unit\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Actions\Admin\AdminLeave\ApproveAdminLeaveRequestAction;
use App\Actions\Admin\AdminLeave\RejectAdminLeaveRequestAction;
use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use App\Enums\ErrorCode;
use App\Models\AdminLeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecideAdminLeaveRequestActionTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create();
        $this->decider = User::factory()->create();
    }

    public function testApproveSetsStatusDeciderAndTimestamp(): void
    {
        $request = $this->pendingRequest();

        $result = $this->approve($request);

        $this->assertNull($result->getError());
        $request->refresh();
        $this->assertSame(AdminLeaveRequestStatus::Approved, $request->status);
        $this->assertSame($this->decider->id, $request->decided_by_id);
        $this->assertNotNull($request->decided_at);
        $this->assertNull($request->reject_reason);
    }

    public function testRejectAdditionallyStoresTheReason(): void
    {
        $request = $this->pendingRequest();

        $result = $this->reject($request, 'Coverage already thin that week');

        $this->assertNull($result->getError());
        $request->refresh();
        $this->assertSame(AdminLeaveRequestStatus::Rejected, $request->status);
        $this->assertSame($this->decider->id, $request->decided_by_id);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('Coverage already thin that week', $request->reject_reason);
    }

    public function testApproveRefusesWhenTheDeciderIsTheRequester(): void
    {
        $request = $this->pendingRequest();

        $result = $this->app->make(ApproveAdminLeaveRequestAction::class)($this->requester, $request);

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertSame(403, $result->getStatus());
        $this->assertSame(AdminLeaveRequestStatus::Pending, $request->refresh()->status);
    }

    public function testRejectRefusesWhenTheDeciderIsTheRequester(): void
    {
        $request = $this->pendingRequest();

        $result = $this->app->make(RejectAdminLeaveRequestAction::class)($this->requester, $request, 'No');

        $this->assertSame(ErrorCode::Forbidden, $result->getError());
        $this->assertSame(403, $result->getStatus());
        $this->assertSame(AdminLeaveRequestStatus::Pending, $request->refresh()->status);
    }

    public function testASecondApprovalConflictsAndLeavesTheOriginalDecisionIntact(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request);
        $original = $request->fresh();

        $otherDecider = User::factory()->create();
        $result = $this->app->make(ApproveAdminLeaveRequestAction::class)($otherDecider, $request->fresh());

        $this->assertSame(ErrorCode::AdminLeaveRequestNotPending, $result->getError());
        $this->assertSame(409, $result->getStatus());
        $this->assertSame($original->decided_by_id, $request->fresh()->decided_by_id);
        $this->assertEquals($original->decided_at, $request->fresh()->decided_at);
    }

    public function testRejectingAnAlreadyApprovedRequestConflicts(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request);

        $result = $this->reject($request->fresh(), 'Changed my mind');

        $this->assertSame(ErrorCode::AdminLeaveRequestNotPending, $result->getError());
        $this->assertSame(409, $result->getStatus());
        $this->assertSame(AdminLeaveRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull($request->fresh()->reject_reason);
    }

    public function testApprovingAnAlreadyRejectedRequestConflictsAndKeepsTheReason(): void
    {
        $request = $this->pendingRequest();
        $this->reject($request, 'Coverage already thin');

        $result = $this->approve($request->fresh());

        $this->assertSame(ErrorCode::AdminLeaveRequestNotPending, $result->getError());
        $this->assertSame(AdminLeaveRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('Coverage already thin', $request->fresh()->reject_reason);
    }

    public function testADisabledDeciderIsStillResolvableOnTheDecisionRecord(): void
    {
        $request = $this->pendingRequest();
        $this->approve($request);

        // Users are deactivated rather than deleted; the decision record must survive it.
        $this->decider->update(['is_disabled' => true]);

        $decided = AdminLeaveRequest::query()->with('decidedBy')->findOrFail($request->id);
        $this->assertNotNull($decided->decidedBy);
        $this->assertSame($this->decider->id, $decided->decidedBy->id);
        $this->assertNotEmpty($decided->decidedBy->name);
    }

    /**
     * A pending row built directly. That shortcut is only legitimate because
     * AdminLeaveLifecycleTest reaches the same state through the real POST endpoint and asserts
     * it; this test is about what happens to a pending request, not how it becomes one.
     */
    private function pendingRequest(): AdminLeaveRequest
    {
        return AdminLeaveRequest::query()->create([
            'requester_id' => $this->requester->id,
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'leave_days' => 5,
            'reason' => 'Family trip',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);
    }

    private function approve(AdminLeaveRequest $request): ActionResult
    {
        return $this->app->make(ApproveAdminLeaveRequestAction::class)($this->decider, $request);
    }

    private function reject(AdminLeaveRequest $request, string $reason): ActionResult
    {
        return $this->app->make(RejectAdminLeaveRequestAction::class)($this->decider, $request, $reason);
    }
}
