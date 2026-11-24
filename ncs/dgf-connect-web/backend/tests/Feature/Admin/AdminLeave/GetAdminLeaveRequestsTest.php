<?php

namespace Tests\Feature\Admin\AdminLeave;

use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use App\Enums\PermissionName;
use App\Models\AdminLeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class GetAdminLeaveRequestsTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
        $this->giveGlobalPermission($this->manager, PermissionName::AdminLeaveManage);
    }

    public function testADateFilterReturnsARequestThatMerelyOverlapsTheRange(): void
    {
        // Spans the boundary: starts in August, ends in September. Containment-only matching
        // would drop it from a September filter.
        $overlapping = $this->request($this->alice, ['start_date' => '2027-08-25', 'end_date' => '2027-09-05']);
        $outside = $this->request($this->alice, ['start_date' => '2027-10-01', 'end_date' => '2027-10-03']);

        $ids = $this->listIds(['date_from' => '2027-09-01', 'date_to' => '2027-09-30']);

        $this->assertContains($overlapping->id, $ids);
        $this->assertNotContains($outside->id, $ids);
    }

    public function testARequestFullyContainingTheFilterRangeIsReturned(): void
    {
        $enclosing = $this->request($this->alice, ['start_date' => '2027-08-01', 'end_date' => '2027-12-31']);

        $this->assertContains($enclosing->id, $this->listIds(['date_from' => '2027-09-10', 'date_to' => '2027-09-12']));
    }

    public function testADatelessExpenseClaimIsReturnedByADateFilterCoveringItsSubmissionDate(): void
    {
        // The regression guard for the default-filter trap: an expense claim carrying no leave
        // period must be matched on when it was submitted, not hidden.
        $claim = $this->request($this->alice, [
            'type' => AdminLeaveType::ExpenseClaim->value,
            'start_date' => null,
            'end_date' => null,
            'created_at' => Carbon::parse('2027-09-15 10:00:00'),
        ]);

        $ids = $this->listIds(['date_from' => '2027-09-01', 'date_to' => '2027-09-30']);

        $this->assertContains($claim->id, $ids);
    }

    public function testADatelessExpenseClaimSubmittedOutsideTheRangeIsExcluded(): void
    {
        $claim = $this->request($this->alice, [
            'type' => AdminLeaveType::ExpenseClaim->value,
            'start_date' => null,
            'end_date' => null,
            'created_at' => Carbon::parse('2027-07-15 10:00:00'),
        ]);

        $this->assertNotContains($claim->id, $this->listIds(['date_from' => '2027-09-01', 'date_to' => '2027-09-30']));
    }

    public function testADatelessClaimSubmittedOnTheLastDayOfTheRangeIsIncluded(): void
    {
        // Late on the boundary day: a naive `created_at <= date_to` comparison against midnight
        // would drop it.
        $claim = $this->request($this->alice, [
            'type' => AdminLeaveType::ExpenseClaim->value,
            'start_date' => null,
            'end_date' => null,
            'created_at' => Carbon::parse('2027-09-30 23:30:00'),
        ]);

        $this->assertContains($claim->id, $this->listIds(['date_from' => '2027-09-01', 'date_to' => '2027-09-30']));
    }

    public function testTheRequesterTypeAndStatusFiltersNarrowAndCombine(): void
    {
        $target = $this->request($this->alice, [
            'type' => AdminLeaveType::PaidLeave->value,
            'status' => AdminLeaveRequestStatus::Approved->value,
        ]);
        $wrongRequester = $this->request($this->bob, [
            'type' => AdminLeaveType::PaidLeave->value,
            'status' => AdminLeaveRequestStatus::Approved->value,
        ]);
        $wrongType = $this->request($this->alice, [
            'type' => AdminLeaveType::DayOff->value,
            'status' => AdminLeaveRequestStatus::Approved->value,
        ]);
        $wrongStatus = $this->request($this->alice, [
            'type' => AdminLeaveType::PaidLeave->value,
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);

        $ids = $this->listIds([
            'requester_id' => $this->alice->id,
            'type' => AdminLeaveType::PaidLeave->value,
            'status' => AdminLeaveRequestStatus::Approved->value,
        ]);

        $this->assertSame([$target->id], $ids);
        $this->assertNotContains($wrongRequester->id, $ids);
        $this->assertNotContains($wrongType->id, $ids);
        $this->assertNotContains($wrongStatus->id, $ids);
    }

    public function testTheAllValueLeavesTheStatusAndTypeFiltersOpen(): void
    {
        $pending = $this->request($this->alice, ['status' => AdminLeaveRequestStatus::Pending->value]);
        $approved = $this->request($this->alice, ['status' => AdminLeaveRequestStatus::Approved->value]);

        $ids = $this->listIds(['status' => 'all', 'type' => 'all']);

        $this->assertContains($pending->id, $ids);
        $this->assertContains($approved->id, $ids);
    }

    public function testTheListIsNotScopedByWorkplaceAndCoversEveryRequester(): void
    {
        $fromAlice = $this->request($this->alice);
        $fromBob = $this->request($this->bob);

        $ids = $this->listIds();

        $this->assertContains($fromAlice->id, $ids);
        $this->assertContains($fromBob->id, $ids);
    }

    public function testResultsAreOrderedBySubmissionTimestampDescending(): void
    {
        $older = $this->request($this->alice, ['created_at' => Carbon::parse('2027-09-01 09:00:00')]);
        $newer = $this->request($this->alice, ['created_at' => Carbon::parse('2027-09-02 09:00:00')]);

        $this->assertSame([$newer->id, $older->id], $this->listIds());
    }

    public function testPendingDecisionFieldsAreReturnedAsNull(): void
    {
        $this->request($this->alice, ['status' => AdminLeaveRequestStatus::Pending->value]);

        $row = $this->list()->json('data.data.0');

        $this->assertNull($row['decided_by_id']);
        $this->assertNull($row['decided_by_name']);
        $this->assertNull($row['decided_at']);
        $this->assertNull($row['reject_reason']);
    }

    public function testADecidedRowCarriesTheDeciderNameAndTimestamp(): void
    {
        $this->request($this->alice, [
            'status' => AdminLeaveRequestStatus::Rejected->value,
            'decided_by_id' => $this->manager->id,
            'decided_at' => Carbon::parse('2027-09-03 12:00:00'),
            'reject_reason' => 'Coverage already thin',
        ]);

        $row = $this->list()->json('data.data.0');

        $this->assertSame($this->manager->id, $row['decided_by_id']);
        $this->assertSame($this->manager->name, $row['decided_by_name']);
        $this->assertNotNull($row['decided_at']);
        $this->assertSame('Coverage already thin', $row['reject_reason']);
    }

    public function testTheRowCarriesTheRequesterAndAttachmentCount(): void
    {
        $this->alice->update(['profile_picture' => 'avatars/alice.png']);
        $request = $this->request($this->alice);
        $request->attachments()->create([
            'disk' => 'local',
            'path' => 'admin-leave/1/a.png',
            'original_name' => 'a.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
        ]);

        $row = $this->list()->json('data.data.0');

        $this->assertSame($this->alice->id, $row['requester_id']);
        $this->assertSame($this->alice->name, $row['requester_name']);
        $this->assertSame('avatars/alice.png', $row['requester_profile_picture']);
        $this->assertSame(1, $row['attachments_count']);
    }

    public function testTheRowReturnsANullProfilePictureWhenTheRequesterHasNoAvatar(): void
    {
        $this->alice->update(['profile_picture' => null]);
        $this->request($this->alice);

        $row = $this->list()->json('data.data.0');

        $this->assertNull($row['requester_profile_picture']);
    }

    public function testOverlapIsFlaggedForIntersectingLiveRequestsFromTheSameRequester(): void
    {
        $long = $this->request($this->alice, [
            'type' => AdminLeaveType::LongLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-20',
            'status' => AdminLeaveRequestStatus::Approved->value,
        ]);
        $inside = $this->request($this->alice, [
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-11',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);

        $rows = collect($this->list()->json('data.data'))->keyBy('id');

        $this->assertTrue($rows[$long->id]['has_overlap']);
        $this->assertTrue($rows[$inside->id]['has_overlap']);
    }

    public function testOverlapIsNotFlaggedAcrossDifferentRequesters(): void
    {
        $fromAlice = $this->request($this->alice, ['start_date' => '2027-09-06', 'end_date' => '2027-09-10']);
        $fromBob = $this->request($this->bob, ['start_date' => '2027-09-06', 'end_date' => '2027-09-10']);

        $rows = collect($this->list()->json('data.data'))->keyBy('id');

        $this->assertFalse($rows[$fromAlice->id]['has_overlap']);
        $this->assertFalse($rows[$fromBob->id]['has_overlap']);
    }

    public function testARejectedRequestDoesNotCountAsAnOverlap(): void
    {
        $this->request($this->alice, [
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-20',
            'status' => AdminLeaveRequestStatus::Rejected->value,
        ]);
        $live = $this->request($this->alice, [
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-11',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);

        $rows = collect($this->list()->json('data.data'))->keyBy('id');

        $this->assertFalse($rows[$live->id]['has_overlap']);
    }

    public function testAdjacentButNonIntersectingRequestsAreNotFlagged(): void
    {
        $first = $this->request($this->alice, ['start_date' => '2027-09-06', 'end_date' => '2027-09-10']);
        $second = $this->request($this->alice, ['start_date' => '2027-09-11', 'end_date' => '2027-09-15']);

        $rows = collect($this->list()->json('data.data'))->keyBy('id');

        $this->assertFalse($rows[$first->id]['has_overlap']);
        $this->assertFalse($rows[$second->id]['has_overlap']);
    }

    public function testFiltersSelectCorrectlyAgainstLeftoverDecidedRequestsFromAnEarlierCycle(): void
    {
        // History from a previous review cycle: already-decided rows that must not be picked up
        // by a pending filter, nor push the live one off the page.
        $this->request($this->alice, [
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-08',
            'status' => AdminLeaveRequestStatus::Approved->value,
            'decided_by_id' => $this->manager->id,
            'decided_at' => Carbon::parse('2027-08-20 09:00:00'),
            'created_at' => Carbon::parse('2027-08-19 09:00:00'),
        ]);
        $this->request($this->alice, [
            'start_date' => '2027-09-09',
            'end_date' => '2027-09-10',
            'status' => AdminLeaveRequestStatus::Rejected->value,
            'decided_by_id' => $this->manager->id,
            'decided_at' => Carbon::parse('2027-08-21 09:00:00'),
            'reject_reason' => 'Old refusal',
            'created_at' => Carbon::parse('2027-08-19 10:00:00'),
        ]);
        $live = $this->request($this->alice, [
            'start_date' => '2027-09-20',
            'end_date' => '2027-09-21',
            'status' => AdminLeaveRequestStatus::Pending->value,
            'created_at' => Carbon::parse('2027-09-01 09:00:00'),
        ]);

        $pendingIds = $this->listIds([
            'date_from' => '2027-09-01',
            'date_to' => '2027-09-30',
            'status' => AdminLeaveRequestStatus::Pending->value,
        ]);
        $this->assertSame([$live->id], $pendingIds);

        // Without a status filter the whole history is still visible.
        $allIds = $this->listIds(['date_from' => '2027-09-01', 'date_to' => '2027-09-30', 'status' => 'all']);
        $this->assertCount(3, $allIds);
    }

    /**
     * @param array<string, mixed> $filters
     * @return int[]
     */
    private function listIds(array $filters = []): array
    {
        return array_column($this->list($filters)->json('data.data'), 'id');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function list(array $filters = []): TestResponse
    {
        return $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests?' . http_build_query($filters))
            ->assertOk();
    }

    /**
     * Rows are built directly here: this test is about how the filter and overlap query select
     * among many pre-existing requests, and AdminLeaveLifecycleTest proves each state is
     * reachable through the real endpoints.
     *
     * @param array<string, mixed> $attributes
     */
    private function request(User $requester, array $attributes = []): AdminLeaveRequest
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        /** @var AdminLeaveRequest $request */
        $request = AdminLeaveRequest::query()->create([
            'requester_id' => $requester->id,
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'leave_days' => 5,
            'reason' => 'Family trip',
            'status' => AdminLeaveRequestStatus::Pending->value,
            ...$attributes,
        ]);

        if ($createdAt !== null) {
            $request->forceFill(['created_at' => $createdAt])->save();
        }

        return $request->fresh();
    }
}
