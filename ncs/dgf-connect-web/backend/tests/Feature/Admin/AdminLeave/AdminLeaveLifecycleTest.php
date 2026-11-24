<?php

namespace Tests\Feature\Admin\AdminLeave;

use App\Contracts\HolidayProviderContract;
use App\Enums\AdminLeaveRequestStatus;
use App\Enums\AdminLeaveType;
use App\Enums\CompanyHolidaySource;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Models\AdminLeaveRequest;
use App\Models\CompanyHoliday;
use App\Models\CompanyHolidaySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\Support\FakeHolidayProvider;
use Tests\TestCase;

/**
 * Every state here is reached by calling the real endpoints in order — create, then list, then
 * decide — rather than by inserting a row in the state under test.
 */
class AdminLeaveLifecycleTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->requester = User::factory()->create();
        $this->giveGlobalPermission($this->manager, PermissionName::AdminLeaveManage);
        $this->giveGlobalPermission($this->requester, PermissionName::ManagerAuth);

        // "Today" is fixed so the not-in-the-past rule has a stable reference.
        Carbon::setTestNow(Carbon::parse('2027-09-01 09:00:00', config('app.timezone')));

        $this->app->bind(
            HolidayProviderContract::class,
            fn() => new FakeHolidayProvider([
                2027 => ['2027-09-20' => '敬老の日', '2027-09-23' => '秋分の日'],
            ])
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testCreateThenListThenApprove(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'reason' => 'Family trip',
        ]);

        $pendingRow = $this->findInManagerList($id);
        $this->assertSame(AdminLeaveRequestStatus::Pending->value, $pendingRow['status']);
        $this->assertNull($pendingRow['decided_by_name']);
        $this->assertNull($pendingRow['decided_at']);
        $this->assertSame(5, $pendingRow['leave_days']);

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/admin-leave-requests/' . $id . '/approve')
            ->assertOk();

        $approvedRow = $this->findInManagerList($id);
        $this->assertSame(AdminLeaveRequestStatus::Approved->value, $approvedRow['status']);
        $this->assertSame($this->manager->name, $approvedRow['decided_by_name']);
        $this->assertNotNull($approvedRow['decided_at']);
        $this->assertNull($approvedRow['reject_reason']);
    }

    public function testCreateThenRejectThenASecondDecisionConflicts(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::DayOff->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-07',
            'reason' => 'Personal',
        ]);

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/admin-leave-requests/' . $id . '/reject', [
                'reject_reason' => 'Coverage already thin that week',
            ])
            ->assertOk();

        $row = $this->findInManagerList($id);
        $this->assertSame(AdminLeaveRequestStatus::Rejected->value, $row['status']);
        $this->assertSame('Coverage already thin that week', $row['reject_reason']);
        $this->assertSame($this->manager->name, $row['decided_by_name']);

        $otherManager = User::factory()->create();
        $this->giveGlobalPermission($otherManager, PermissionName::AdminLeaveManage);
        $this->actingAs($otherManager, 'api')
            ->postJson('/admin/admin-leave-requests/' . $id . '/approve')
            ->assertStatus(409)
            ->assertJsonPath('error', ErrorCode::AdminLeaveRequestNotPending->value);

        // The original refusal survives the losing second decision untouched.
        $unchanged = $this->findInManagerList($id);
        $this->assertSame(AdminLeaveRequestStatus::Rejected->value, $unchanged['status']);
        $this->assertSame($this->manager->name, $unchanged['decided_by_name']);
    }

    public function testRejectionWithoutAReasonIsRejectedAndLeavesTheRequestPending(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::DayOff->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-07',
            'reason' => 'Personal',
        ]);

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/admin-leave-requests/' . $id . '/reject', [])
            ->assertStatus(422);

        $this->assertSame(AdminLeaveRequestStatus::Pending, AdminLeaveRequest::query()->find($id)->status);
    }

    public function testCreatingARequestInAnUngeneratedYearGeneratesItAndCountsAgainstIt(): void
    {
        $this->assertSame(0, CompanyHoliday::query()->count());

        $id = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-20',
            'end_date' => '2027-09-24',
            'reason' => 'Autumn break',
        ]);

        // The year was generated as a side effect of the count, so the two holidays inside the
        // range are excluded instead of being invisible.
        $this->assertSame(2, CompanyHoliday::query()->whereYear('date', 2027)->count());
        $this->assertSame(3, AdminLeaveRequest::query()->find($id)->leave_days);
    }

    public function testTheStoredDayCountIsNotRewrittenByALaterCalendarChange(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'reason' => 'Family trip',
        ]);
        $this->assertSame(5, AdminLeaveRequest::query()->find($id)->leave_days);

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/company-holidays', ['date' => '2027-09-08', 'name' => '社内休業日'])
            ->assertStatus(201);
        $this->actingAs($this->manager, 'api')
            ->putJson('/admin/company-holidays/settings', ['year' => 2027, 'exclude_weekends' => true])
            ->assertOk();

        $this->assertSame(5, AdminLeaveRequest::query()->find($id)->leave_days);
        $this->assertSame(5, $this->findInManagerList($id)['leave_days']);
    }

    public function testADatelessExpenseClaimIsCreatedAndVisibleUnderTheCurrentMonthFilter(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::ExpenseClaim->value,
            'reason' => 'Taxi fare 4,300 JPY',
        ]);

        $stored = AdminLeaveRequest::query()->find($id);
        $this->assertNull($stored->start_date);
        $this->assertNull($stored->leave_days);

        // The web admin's default filter is the current month.
        $rows = $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests?date_from=2027-09-01&date_to=2027-09-30')
            ->assertOk()
            ->json('data.data');

        $this->assertContains($id, array_column($rows, 'id'));
    }

    public function testTheRequesterSeesTheOutcomeOnTheirOwnList(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-10',
            'reason' => 'Family trip',
        ]);

        $this->actingAs($this->manager, 'api')
            ->postJson('/admin/admin-leave-requests/' . $id . '/approve')
            ->assertOk();

        $row = $this->actingAs($this->requester, 'api')
            ->getJson('/admin/admin-leave-requests/mine')
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame($id, $row['id']);
        $this->assertSame(AdminLeaveRequestStatus::Approved->value, $row['status']);
        $this->assertSame($this->manager->name, $row['decided_by_name']);
    }

    public function testTheDetailNamesTheConflictingRequestWhenTheyOverlap(): void
    {
        $long = $this->submit([
            'type' => AdminLeaveType::LongLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-20',
            'reason' => 'Long leave',
        ]);
        $inside = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-11',
            'reason' => 'Paid leave inside the long leave',
        ]);

        $detail = $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests/' . $inside)
            ->assertOk()
            ->json('data');

        $this->assertTrue($detail['has_overlap']);
        $this->assertSame($long, $detail['conflict']['id']);
        $this->assertSame('2027-09-06', $detail['conflict']['start_date']);
        $this->assertSame('2027-09-20', $detail['conflict']['end_date']);
        $this->assertSame(AdminLeaveRequestStatus::Pending->value, $detail['conflict']['status']);
    }

    public function testTheCreateResponseAlreadyNamesTheConflictingRequestWhenTheyOverlap(): void
    {
        $long = $this->submit([
            'type' => AdminLeaveType::LongLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-20',
            'reason' => 'Long leave',
        ]);

        // The create response reuses the detail payload shape, so a client must not read a
        // freshly created, genuinely overlapping request as conflict-free.
        $response = $this->submitRaw([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-11',
            'reason' => 'Paid leave inside the long leave',
        ])
            ->assertStatus(201)
            ->json('data');

        $this->assertTrue($response['has_overlap']);
        $this->assertSame($long, $response['conflict']['id']);
        $this->assertSame('2027-09-06', $response['conflict']['start_date']);
        $this->assertSame('2027-09-20', $response['conflict']['end_date']);
        $this->assertSame(AdminLeaveRequestStatus::Pending->value, $response['conflict']['status']);
    }

    public function testThePreviewMatchesTheCountStoredWhenTheSameRangeIsSubmitted(): void
    {
        $preview = $this->actingAs($this->requester, 'api')
            ->postJson('/admin/admin-leave-requests/preview-days', [
                'start_date' => '2027-09-20',
                'end_date' => '2027-09-24',
            ])
            ->assertOk()
            ->json('data.leave_days');

        // The preview creates no request of its own.
        $this->assertSame(0, AdminLeaveRequest::query()->count());

        $id = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-20',
            'end_date' => '2027-09-24',
            'reason' => 'Autumn break',
        ]);

        $this->assertSame($preview, AdminLeaveRequest::query()->find($id)->leave_days);
    }

    public function testThePreviewAppliesTheWeekendSettingLikeSubmissionDoes(): void
    {
        CompanyHolidaySetting::query()->create(['year' => 2027, 'exclude_weekends' => true]);
        CompanyHoliday::query()->create([
            'date' => '2027-09-20',
            'name' => '敬老の日',
            'source' => CompanyHolidaySource::Library->value,
        ]);

        // 2027-09-18 (Sat) .. 2027-09-24 (Fri) is seven dates; the weekend pair (18, 19) and the
        // 20th holiday drop out, leaving 21–24.
        $preview = $this->actingAs($this->requester, 'api')
            ->postJson('/admin/admin-leave-requests/preview-days', [
                'start_date' => '2027-09-18',
                'end_date' => '2027-09-24',
            ])
            ->assertOk()
            ->json('data.leave_days');

        $this->assertSame(4, $preview);
    }

    public function testThePreviewRejectsAReversedOrOverlongRange(): void
    {
        $this->actingAs($this->requester, 'api')
            ->postJson('/admin/admin-leave-requests/preview-days', [
                'start_date' => '2027-09-10',
                'end_date' => '2027-09-06',
            ])
            ->assertStatus(422);

        $this->actingAs($this->requester, 'api')
            ->postJson('/admin/admin-leave-requests/preview-days', [
                'start_date' => '2027-01-01',
                'end_date' => '2028-06-01',
            ])
            ->assertStatus(422);
    }

    public function testSubmissionRejectsInvalidDatesAndTypes(): void
    {
        $this->submitRaw(['type' => 'sabbatical', 'reason' => 'x'])->assertStatus(422);

        // Reversed range.
        $this->submitRaw([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-10',
            'end_date' => '2027-09-06',
            'reason' => 'x',
        ])->assertStatus(422);

        // Start date in the past for a leave type.
        $this->submitRaw([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-08-31',
            'end_date' => '2027-09-02',
            'reason' => 'x',
        ])->assertStatus(422);

        // Missing dates on a leave type.
        $this->submitRaw([
            'type' => AdminLeaveType::DayOff->value,
            'reason' => 'x',
        ])->assertStatus(422);

        // Span over 366 days.
        $this->submitRaw([
            'type' => AdminLeaveType::LongLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2028-09-30',
            'reason' => 'x',
        ])->assertStatus(422);

        // Empty reason.
        $this->submitRaw([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-07',
            'reason' => '',
        ])->assertStatus(422);

        $this->assertSame(0, AdminLeaveRequest::query()->count());
    }

    public function testAnExpenseClaimMayCarryPastDatesButNotUnboundedlyPastOnes(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::ExpenseClaim->value,
            'start_date' => '2027-08-01',
            'end_date' => '2027-08-02',
            'reason' => 'Train fare already paid',
        ]);
        $this->assertSame(2, AdminLeaveRequest::query()->find($id)->leave_days);

        $this->submitRaw([
            'type' => AdminLeaveType::ExpenseClaim->value,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-02',
            'reason' => 'Far too old',
        ])->assertStatus(422);
    }

    public function testALeaveRequestStartingTodayIsAccepted(): void
    {
        $id = $this->submit([
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-01',
            'end_date' => '2027-09-01',
            'reason' => 'Same-day leave',
        ]);

        $this->assertSame(AdminLeaveRequestStatus::Pending, AdminLeaveRequest::query()->find($id)->status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function submit(array $payload): int
    {
        return $this->submitRaw($payload)->assertStatus(201)->json('data.id');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function submitRaw(array $payload): TestResponse
    {
        return $this->actingAs($this->requester, 'api')->postJson('/admin/admin-leave-requests', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function findInManagerList(int $id): array
    {
        $rows = $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests')
            ->assertOk()
            ->json('data.data');

        $row = collect($rows)->firstWhere('id', $id);
        $this->assertNotNull($row, "Request {$id} was not present in the manager list.");

        return $row;
    }
}
