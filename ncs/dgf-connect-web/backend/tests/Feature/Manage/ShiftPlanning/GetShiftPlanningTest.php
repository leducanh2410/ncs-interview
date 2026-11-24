<?php

namespace Tests\Feature\Manage\ShiftPlanning;

use App\Enums\ErrorCode;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use App\Services\Manage\ShiftPlanningLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the Shift Planner read path: GET /manage/shift-planning/ -> GetShiftPlanningAction.
 * Verifies permission gating, window clamping (min date = today + 2, end of next month),
 * the assembled payload (shifts / live assignments / drafts / lock state), and the
 * smart-default member roster (recent workers + window-visible members, document-expiry
 * alerts). GetShiftPlanningAction previously had 0% coverage.
 */
class GetShiftPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $amType;

    private WorkplacePosition $position;

    protected function setUp(): void
    {
        parent::setUp();

        // today + 2 = 2026-06-20 (min plannable date); window runs through 2026-07-31.
        Carbon::setTestNow('2026-06-18 08:00:00');

        $this->manager = User::factory()->create();
        foreach ([PermissionName::ShiftList, PermissionName::ShiftCreate, PermissionName::ShiftEdit] as $name) {
            $permission = Permission::create(['name' => $name->value, 'display_name' => $name->value]);
            $this->manager->givePermission($permission, null);
        }

        $this->workplace = Workplace::create([
            'name' => 'Planner WP',
            'address' => 'Addr',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
            'min_rest_hours' => 9,
        ]);

        $this->amType = $this->shiftType('09:00:00', '13:00:00');
        $this->position = WorkplacePosition::create(['workplace_id' => $this->workplace->id, 'name' => 'Staff']);
    }

    protected function tearDown(): void
    {
        app(ShiftPlanningLockService::class)->release($this->workplace->id, $this->manager->id);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testPermissionDeniedWithoutShiftList(): void
    {
        $unauthorized = User::factory()->create();

        $response = $this->actingAs($unauthorized, 'api')->getJson(
            '/manage/shift-planning/?workplace_id=' . $this->workplace->id
        );

        $response->assertStatus(403);
    }

    public function testNotFoundForUnknownWorkplace(): void
    {
        $response = $this->index(['workplace_id' => 999999]);

        $response->assertStatus(404);
        $response->assertJsonPath('error', ErrorCode::NotFound->value);
    }

    public function testWindowDefaultsFromMinDateToEndOfNextMonth(): void
    {
        // No `from` supplied -> anchor = min date (today + 2), `to` = end of next month.
        $response = $this->index();

        $response->assertOk();
        $response->assertJsonPath('data.window.from', '2026-06-20');
        $response->assertJsonPath('data.window.to', '2026-07-31');
        $response->assertJsonPath('data.window.min_date', '2026-06-20');
    }

    public function testWindowClampsExplicitFromBeforeMinimumDate(): void
    {
        // today and tomorrow are not plannable: an explicit earlier `from` is clamped up.
        $response = $this->index(['from' => '2026-06-15']);

        $response->assertOk();
        $response->assertJsonPath('data.window.from', '2026-06-20');
        $response->assertJsonPath('data.window.min_date', '2026-06-20');
        // `to` still derives from the original anchor month (June -> end of July).
        $response->assertJsonPath('data.window.to', '2026-07-31');
    }

    public function testReturnsShiftsLiveAssignmentsDraftsAndLockState(): void
    {
        app(ShiftPlanningLockService::class)->acquire($this->workplace->id, $this->manager->id, $this->manager->name);

        $member = $this->createMember('98001');
        $shift = $this->createShift($this->amType, '2026-06-22');
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->createDraft('2026-06-22', $this->amType, $member);

        $response = $this->index();

        $response->assertOk();
        $shifts = collect($response->json('data.shifts'));
        $this->assertCount(1, $shifts);
        $this->assertSame($shift->id, $shifts->first()['id']);

        $liveAssignments = collect($response->json('data.live_assignments'));
        $this->assertTrue($liveAssignments->contains(fn($row) => $row['user_id'] === $member->id));

        $drafts = collect($response->json('data.drafts'));
        $this->assertTrue($drafts->contains(fn($row) => $row['user_id'] === $member->id));

        $lock = $response->json('data.lock');
        $this->assertNotNull($lock);
        $this->assertTrue($lock['is_me']);
        $this->assertSame($this->manager->id, $lock['user_id']);
    }

    public function testLiveAssignmentsExcludeUnapprovedShiftUsers(): void
    {
        $shift = $this->createShift($this->amType, '2026-06-22');

        $approved = $this->createMember('98101');
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $approved->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $pending = $this->createMember('98102');
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $pending->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $rejected = $this->createMember('98103');
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $rejected->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Rejected->value,
        ]);

        $response = $this->index();

        $response->assertOk();
        $liveUserIds = collect($response->json('data.live_assignments'))->pluck('user_id')->all();
        $this->assertContains($approved->id, $liveUserIds);
        $this->assertNotContains($pending->id, $liveUserIds);
        $this->assertNotContains($rejected->id, $liveUserIds);
    }

    public function testRosterIncludesRecentAndWindowMembersAndExcludesInactive(): void
    {
        // Recent worker: approved assignment on a shift inside the 60-day activity window
        // (dated before the planning window, so it does NOT appear in `shifts`).
        $recentMember = $this->createMember('98002');
        $recentShift = $this->createShift($this->amType, '2026-06-10');
        ShiftUser::create([
            'shift_id' => $recentShift->id,
            'user_id' => $recentMember->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        // Window-visible only: appears via a draft inside the planning window.
        $draftMember = $this->createMember('98003');
        $this->createDraft('2026-06-22', $this->amType, $draftMember);

        // Inactive: workplace member with no recent work, no draft, no fixed schedule.
        $inactiveMember = $this->createMember('98004');

        $response = $this->index();

        $response->assertOk();
        $memberIds = collect($response->json('data.members'))->pluck('id')->all();
        $this->assertContains($recentMember->id, $memberIds);
        $this->assertContains($draftMember->id, $memberIds);
        $this->assertNotContains($inactiveMember->id, $memberIds);
    }

    public function testMemberActivityDaysZeroLoadsFullRoster(): void
    {
        // member_activity_days = 0 disables the smart default and loads the full roster.
        $inactiveMember = $this->createMember('98005');

        $response = $this->index(['member_activity_days' => 0]);

        $response->assertOk();
        $memberIds = collect($response->json('data.members'))->pluck('id')->all();
        $this->assertContains($inactiveMember->id, $memberIds);
    }

    public function testFlagsExpiringDocumentsOnMembers(): void
    {
        // document_expiry_alert = 14 days -> alert date = 2026-07-02.
        $member = $this->createMember('98006');
        $member->info->update([
            'passport_expiry_date' => '2026-06-25', // <= alert date -> flagged
            'residence_expiry_date_1' => '2027-01-01', // far future -> not flagged
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
        ]);
        // Make the member visible to the roster via a window draft.
        $this->createDraft('2026-06-22', $this->amType, $member);

        $response = $this->index();

        $response->assertOk();
        $members = collect($response->json('data.members'));
        $row = $members->firstWhere('id', $member->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['passport_expiry_alert']);
        $this->assertFalse($row['residence_card_expiry_alert']);
    }

    public function testRecentShiftCountCountsApprovedWorkedShiftsInActivityWindow(): void
    {
        // Activity window for the count = today - 60 ... today (2026-04-19 ... 2026-06-18).
        $member = $this->createMember('98007');

        // Two approved shifts inside the trailing window -> both counted.
        foreach (['2026-06-10', '2026-06-15'] as $date) {
            ShiftUser::create([
                'shift_id' => $this->createShift($this->amType, $date)->id,
                'user_id' => $member->id,
                'workplace_position_id' => $this->position->id,
                'status' => ShiftUserStatus::Approved->value,
            ]);
        }

        // Future shift inside the planning window (after today) -> NOT counted: the count
        // is trailing worked history, not forward-planned assignments.
        ShiftUser::create([
            'shift_id' => $this->createShift($this->amType, '2026-06-22')->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        // Unapproved shift inside the window -> NOT counted.
        ShiftUser::create([
            'shift_id' => $this->createShift($this->amType, '2026-06-12')->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->position->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->index();

        $response->assertOk();
        $response->assertJsonPath('data.window.recent_shift_days', 60);
        $row = collect($response->json('data.members'))->firstWhere('id', $member->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['recent_shift_count']);
    }

    public function testMemberNameUsesShortNameForSriLankaNationality(): void
    {
        $sriLankaMember = $this->createMember('98008');
        $sriLankaMember->update([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $sriLankaMember->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->createDraft('2026-06-22', $this->amType, $sriLankaMember);

        $regularMember = $this->createMember('98009');
        $regularMember->update(['first_name' => 'Taro', 'last_name' => 'Yamada']);
        $regularMember->info->update(['nationality' => Nationality::Vietnam->value]);
        $this->createDraft('2026-06-22', $this->amType, $regularMember);

        $response = $this->index();

        $response->assertOk();
        $members = collect($response->json('data.members'));
        $this->assertSame('THARINDU RAVISHANN', $members->firstWhere('id', $sriLankaMember->id)['name']);
        $this->assertSame('YAMADA TARO', $members->firstWhere('id', $regularMember->id)['name']);
    }

    // ---- helpers ----------------------------------------------------------

    private function index(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager, 'api')->getJson(
            '/manage/shift-planning/?' .
                http_build_query(
                    array_merge(
                        [
                            'workplace_id' => $this->workplace->id,
                        ],
                        $params
                    )
                )
        );
    }

    private function shiftType(string $start, string $end): WorkplaceShift
    {
        return WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $start,
            'end_time' => $end,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'is_active' => true,
        ]);
    }

    private function createMember(string $staffNo): User
    {
        $member = User::factory()->create(['staff_no' => $staffNo]);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);

        return $member;
    }

    private function createShift(WorkplaceShift $type, string $date, int $fixedTotal = 0, int $tempTotal = 5): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $type->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => $fixedTotal,
            'temp_total' => $tempTotal,
        ]);
    }

    private function createDraft(
        string $date,
        WorkplaceShift $type,
        User $user,
        ShiftPlanDraftAction $action = ShiftPlanDraftAction::Add,
        bool $isFixed = false
    ): ShiftPlanDraft {
        return ShiftPlanDraft::create([
            'workplace_id' => $this->workplace->id,
            'date' => $date,
            'workplace_shift_id' => $type->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->position->id,
            'is_fixed' => $isFixed,
            'action' => $action->value,
            'created_by' => $this->manager->id,
        ]);
    }

    public function testPositionsIncludeInactivePositionsStillUsedByFixedSchedules(): void
    {
        $retired = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Retired',
            'is_active' => false,
        ]);
        $unused = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Unused',
            'is_active' => false,
        ]);
        $member = $this->createMember('98007');
        $fixedSchedule = FixedSchedule::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $fixedSchedule->id,
            'workplace_shift_id' => $this->amType->id,
            'workplace_position_id' => $retired->id,
            'day' => 1,
        ]);

        $response = $this->index();

        $response->assertOk();
        $positionIds = collect($response->json('data.positions'))->pluck('id')->all();
        $this->assertContains($retired->id, $positionIds);
        $this->assertNotContains($unused->id, $positionIds);
    }

    public function testMemberFixedSchedulesExposeWorkplacePositionId(): void
    {
        $member = $this->createMember('98006');
        $fixedSchedule = FixedSchedule::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $fixedSchedule->id,
            'workplace_shift_id' => $this->amType->id,
            'workplace_position_id' => $this->position->id,
            'day' => 1,
        ]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $fixedSchedule->id,
            'workplace_shift_id' => $this->amType->id,
            'day' => 2,
        ]);

        $response = $this->index();

        $response->assertOk();
        $fixedSchedules = collect($response->json('data.members'))->firstWhere('id', $member->id)['fixed_schedules'];
        $this->assertSame(
            [
                ['workplace_shift_id' => $this->amType->id, 'day' => 1, 'workplace_position_id' => $this->position->id],
                ['workplace_shift_id' => $this->amType->id, 'day' => 2, 'workplace_position_id' => null],
            ],
            $fixedSchedules
        );
    }
}
