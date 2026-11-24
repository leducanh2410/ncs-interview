<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Events\ShiftUserApproved;
use App\Events\ShiftUserRejected;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssignShiftUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-21 08:00:00');

        $this->manager = User::factory()->create();

        $permission = Permission::create([
            'name' => PermissionName::ShiftEdit->value,
            'display_name' => 'Shift Edit',
        ]);
        $this->manager->givePermission($permission, null);

        $this->workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        $this->shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-25',
            'adjusted_date' => '2026-05-25',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRejectingNonMemberUserSucceeds(): void
    {
        $nonMember = User::factory()->create();
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $nonMember->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempRejectedIds: [$nonMember->id])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $nonMember->id,
            'status' => ShiftUserStatus::Rejected->value,
        ]);
        $this->assertDatabaseMissing('workplace_users', [
            'user_id' => $nonMember->id,
            'workplace_id' => $this->workplace->id,
        ]);
    }

    public function testApprovingNonMemberUserReturns422(): void
    {
        $nonMember = User::factory()->create();
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $nonMember->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$nonMember->id]])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.not_member_ids', [(string) $nonMember->id]);
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $nonMember->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);
    }

    public function testSettingNonMemberUserToPendingReturns422(): void
    {
        $nonMember = User::factory()->create();

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempPendingIds: [$nonMember->id])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.not_member_ids', [(string) $nonMember->id]);
    }

    public function testApprovingMemberUserSucceeds(): void
    {
        Event::fake([ShiftUserApproved::class]);

        $member = User::factory()->create(['staff_no' => '99001']);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testSkipsUpdateWhenStatusAndPositionUnchanged(): void
    {
        $member = User::factory()->create(['staff_no' => '99002']);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => false,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertOk();
        // Record unchanged — still a single row
        $this->assertSame(
            1,
            ShiftUser::query()->where('shift_id', $this->shift->id)->where('user_id', $member->id)->count()
        );
    }

    public function testDirectlyCreatesApprovedShiftUserWhenNoExistingRecord(): void
    {
        Event::fake([ShiftUserApproved::class]);

        $member = User::factory()->create(['staff_no' => '99003']);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        Event::assertDispatched(ShiftUserApproved::class);
    }

    public function testRejectsSiblingShiftPendingRecordWhenUserApproved(): void
    {
        Event::fake([ShiftUserApproved::class]);

        $siblingWorkplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $siblingShift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $siblingWorkplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-25',
            'adjusted_date' => '2026-05-25',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);

        $member = User::factory()->create(['staff_no' => '99004']);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);
        ShiftUser::create([
            'shift_id' => $siblingShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $siblingShift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Rejected->value,
        ]);
    }

    public function testUpdatingShiftInThePastReturns422(): void
    {
        $pastShift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => '2026-05-19',
            'adjusted_date' => '2026-05-19',
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$pastShift->id}",
            $this->buildPayload()
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.date', ['Cannot update shift in the past']);
    }

    public function testWithoutShiftEditPermissionReturns403(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload()
        );

        $response->assertStatus(403);
    }

    public function testWithShiftEditPermissionOnAnotherWorkplaceReturns403(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Workplace',
            'address' => 'Other Address',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        /** @var User $scopedManager */
        $scopedManager = User::factory()->create();
        $scopedManager->givePermission(
            Permission::query()->where('name', PermissionName::ShiftEdit->value)->first(),
            $otherWorkplace
        );

        $response = $this->actingAs($scopedManager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload()
        );

        $response->assertStatus(403);
    }

    public function testApprovingMoreTempUsersThanTempTotalReturns422(): void
    {
        $first = $this->createMember('99101');
        $second = $this->createMember('99102');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(
                tempApprovedIds: [$this->workplacePosition->id => [$first->id, $second->id]],
                tempTotal: 1
            )
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_total', ['Number of approved users exceed the total number.']);
        $this->assertDatabaseCount('shift_users', 0);
    }

    public function testApprovingMoreFixedUsersThanFixedTotalReturns422(): void
    {
        $first = $this->createMember('99103');
        $second = $this->createMember('99104');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(
                fixedApprovedIds: [$this->workplacePosition->id => [$first->id, $second->id]],
                fixedTotal: 1
            )
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.fixed_total', ['Number of approved users exceed the total number.']);
        $this->assertDatabaseCount('shift_users', 0);
    }

    public function testApprovingExactlyTheTotalNumberOfUsersSucceeds(): void
    {
        Event::fake([ShiftUserApproved::class]);

        $temp = $this->createMember('99105');
        $fixed = $this->createMember('99106');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(
                tempApprovedIds: [$this->workplacePosition->id => [$temp->id]],
                fixedApprovedIds: [$this->workplacePosition->id => [$fixed->id]],
                fixedTotal: 1,
                tempTotal: 1
            )
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'user_id' => $fixed->id,
            'is_fixed' => true,
            'status' => ShiftUserStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('shift_users', [
            'user_id' => $temp->id,
            'is_fixed' => false,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testApprovingDisabledUserReturns422(): void
    {
        $member = $this->createMember('99107');
        $member->update(['is_disabled' => true]);

        $this->assertApproveFailsWith($member, 'temp_approved_is_disabled_' . $this->workplacePosition->id);
    }

    public function testApprovingRejectsUserLockedAfterThePreCheckReadTheAccount(): void
    {
        $member = $this->createMember('99120');
        $this->lockAccountAfterFirstRead($member);

        $this->assertApproveFailsWith($member, 'temp_approved_is_disabled_' . $this->workplacePosition->id);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
        ]);
    }

    public function testSettingDisabledUserToPendingReturns422(): void
    {
        $member = $this->createMember('99121');
        $member->update(['is_disabled' => true]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempPendingIds: [$member->id])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.disabled_ids', [(string) $member->id]);
        // A pending row counts as an upcoming shift, so a locked account must not keep one.
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
        ]);
    }

    public function testSettingUserToPendingRejectsAccountLockedAfterThePreCheckReadIt(): void
    {
        $member = $this->createMember('99122');
        $this->lockAccountAfterFirstRead($member);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempPendingIds: [$member->id])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.disabled_ids', [(string) $member->id]);
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
        ]);
    }

    public function testPendingAndApprovedProblemsAreReportedInOneResponse(): void
    {
        $approved = $this->createMember('99124');
        $approved->update(['paper_status' => UserPaperStatus::Rejected->value]);
        $pending = $this->createMember('99125');
        $pending->update(['is_disabled' => true]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(
                tempPendingIds: [$pending->id],
                tempApprovedIds: [$this->workplacePosition->id => [$approved->id]]
            )
        );

        // Both problems come back together, so the admin fixes them in one pass rather than
        // resubmitting to discover the pending one.
        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_approved_rejected_paper_' . $this->workplacePosition->id, [
            (string) $approved->id,
        ]);
        $response->assertJsonPath('data.disabled_ids', [(string) $pending->id]);
    }

    public function testSettingActiveUserToPendingStillSucceeds(): void
    {
        $member = $this->createMember('99123');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempPendingIds: [$member->id])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);
    }

    public function testApprovingUserWithRejectedPaperReturns422(): void
    {
        $member = $this->createMember('99108');
        $member->update(['paper_status' => UserPaperStatus::Rejected->value]);

        $this->assertApproveFailsWith($member, 'temp_approved_rejected_paper_' . $this->workplacePosition->id);
    }

    public function testApprovingUserWithoutStaffNoReturns422(): void
    {
        $member = $this->createMember('99109');
        $member->update(['staff_no' => null]);

        $this->assertApproveFailsWith($member, 'temp_approved_missing_staff_no_' . $this->workplacePosition->id);
    }

    public function testApprovingUserWithExpiringResidenceCardReturns422(): void
    {
        $member = $this->createMember('99110');
        // Shift date is 2026-05-25 and the threshold is 3 days, so this card is too close to expiry.
        $member->info->update(['residence_expiry_date_1' => '2026-05-26']);

        $this->assertApproveFailsWith($member, 'temp_approved_expired_residence_' . $this->workplacePosition->id);
    }

    public function testApprovingUserWithExpiringPassportReturns422(): void
    {
        $member = $this->createMember('99111');
        $member->info->update(['passport_expiry_date' => '2026-05-26']);

        $this->assertApproveFailsWith($member, 'temp_approved_expired_passport_' . $this->workplacePosition->id);
    }

    public function testApprovingUserWithExpiringStudentCardReturns422(): void
    {
        $member = $this->createMember('99112');
        $member->info->update(['student_card_expiry_date' => '2026-05-26']);

        $this->assertApproveFailsWith($member, 'temp_approved_expired_student_' . $this->workplacePosition->id);
    }

    public function testApprovingFixedUserReportsErrorUnderFixedKey(): void
    {
        $member = $this->createMember('99113');
        $member->update(['is_disabled' => true]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(fixedApprovedIds: [$this->workplacePosition->id => [$member->id]], fixedTotal: 1)
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.fixed_approved_is_disabled_' . $this->workplacePosition->id, [
            (string) $member->id,
        ]);
    }

    public function testApprovingUserWithAnOverlappingShiftReturns422(): void
    {
        $member = $this->createMember('99114');
        $this->approveOnShift($member, $this->shift->date, '10:00:00', '16:00:00');

        $this->assertApproveFailsWith($member, 'temp_approved_overlap_' . $this->workplacePosition->id);
    }

    public function testApprovingUserWithoutEnoughRestTimeReturns422(): void
    {
        $member = $this->createMember('99119');
        // Workplaces default to a 9h minimum rest, and this shift ends 7h before ours starts.
        $this->approveOnShift($member, '2026-05-24', '20:00:00', '02:00:00');

        $this->assertApproveFailsWith($member, 'temp_approved_min_rest_hours_' . $this->workplacePosition->id);
    }

    public function testApprovingUserAtTheDailyShiftLimitReturns422(): void
    {
        $member = $this->createMember('99120');
        // The default limit is 2 shifts per day; neither of these overlaps ours.
        $this->approveOnShift($member, '2026-05-25', '18:00:00', '20:00:00');
        $this->approveOnShift($member, '2026-05-25', '20:00:00', '22:00:00');

        $this->assertApproveFailsWith($member, 'temp_approved_max_shift_' . $this->workplacePosition->id);
    }

    public function testApprovingUserOverTheWeeklyHourLimitReturns422(): void
    {
        $member = $this->createMember('99121');
        // 27h already booked in the 7 days up to this shift; ours adds 9h against a 28h limit.
        $this->approveOnShift($member, '2026-05-22', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-23', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-24', '09:00:00', '18:00:00');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_approved_max_hour_' . $this->workplacePosition->id, [
            "{$member->id}:8.00",
        ]);
    }

    public function testShiftsAfterThisShiftCountTowardTheWeeklyLimit(): void
    {
        $member = $this->createMember('99124');
        $this->approveOnShift($member, '2026-05-26', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-27', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-28', '09:00:00', '18:00:00');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_approved_max_hour_' . $this->workplacePosition->id, [
            "{$member->id}:8.00",
        ]);
    }

    public function testWeeklyLimitUsesTheLowestLimitWithinEachWindow(): void
    {
        $member = $this->createMember('99125');
        UserConfig::create([
            'user_id' => $member->id,
            'max_shift_per_day' => 2,
            'max_hour_per_week' => 40,
            'max_hour_per_week_expiry_date' => '2026-05-25',
        ]);
        $this->approveOnShift($member, '2026-05-26', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-27', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-28', '09:00:00', '18:00:00');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_approved_max_hour_' . $this->workplacePosition->id, [
            "{$member->id}:8.00",
        ]);
    }

    public function testShorteningTheBreakCannotPushAnApprovedMemberOverTheWeeklyLimit(): void
    {
        $member = $this->createMember('99126');
        $this->approveOnShift($member, '2026-05-22', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-23', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-24', '09:00:00', '11:00:00');
        $approval = $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]]);
        $this->actingAs($this->manager, 'api')
            ->putJson("/manage/shifts/{$this->shift->id}", [...$approval, 'break_minutes' => 120])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->putJson("/manage/shifts/{$this->shift->id}", [
            ...$approval,
            'break_minutes' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_approved_max_hour_' . $this->workplacePosition->id, [
            "{$member->id}:1.00",
        ]);
        $this->assertSame(120, $this->shift->fresh()->break_minutes);
    }

    public function testShorteningTheBreakFromTheConfigFormCannotPushAnApprovedMemberOverTheWeeklyLimit(): void
    {
        $member = $this->createMember('99127');
        $this->approveOnShift($member, '2026-05-22', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-23', '09:00:00', '18:00:00');
        $this->approveOnShift($member, '2026-05-24', '09:00:00', '11:00:00');
        $this->actingAs($this->manager, 'api')
            ->putJson("/manage/shifts/{$this->shift->id}", [
                ...$this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]]),
                'break_minutes' => 120,
            ])
            ->assertOk();

        $response = $this->actingAs($this->manager, 'api')->patchJson("/manage/shifts/{$this->shift->id}/config", [
            'fixed_total' => 0,
            'temp_total' => 5,
            'break_minutes' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', ErrorCode::ScheduleMaxWorkHoursExceeded->value);
        $response->assertJsonPath('data.0.user_id', $member->id);
        $response->assertJsonPath('data.0.over_hours', 1);
        $this->assertSame(120, $this->shift->fresh()->break_minutes);

        $this->actingAs($this->manager, 'api')
            ->patchJson("/manage/shifts/{$this->shift->id}/config", [
                'fixed_total' => 0,
                'temp_total' => 5,
                'break_minutes' => 180,
            ])
            ->assertOk();
    }

    public function testAlreadyApprovedMemberOverTheWeeklyLimitDoesNotBlockApprovingOthers(): void
    {
        $veteran = $this->createMember('99122');
        $this->actingAs($this->manager, 'api')
            ->putJson(
                "/manage/shifts/{$this->shift->id}",
                $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$veteran->id]])
            )
            ->assertOk();
        $this->approveOnShift($veteran, '2026-05-22', '09:00:00', '18:00:00');
        $this->approveOnShift($veteran, '2026-05-23', '09:00:00', '18:00:00');
        $this->approveOnShift($veteran, '2026-05-24', '09:00:00', '18:00:00');
        $newcomer = $this->createMember('99123');

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$veteran->id, $newcomer->id]])
        );

        $response->assertOk();
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $newcomer->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    public function testMultipleFailingUsersAreGroupedUnderTheSameErrorKey(): void
    {
        $first = $this->createMember('99115');
        $first->update(['is_disabled' => true]);
        $second = $this->createMember('99116');
        $second->update(['is_disabled' => true]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$first->id, $second->id]])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.temp_approved_is_disabled_' . $this->workplacePosition->id, [
            "{$first->id},{$second->id}",
        ]);
    }

    public function testValidationErrorsLeaveShiftUsersUntouched(): void
    {
        $member = $this->createMember('99117');
        $member->update(['is_disabled' => true]);
        ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);

        $this->actingAs($this->manager, 'api')
            ->putJson(
                "/manage/shifts/{$this->shift->id}",
                $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
            )
            ->assertStatus(422);

        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);
    }

    public function testRejectingAnApprovedUserDeletesTheirCheckIn(): void
    {
        Event::fake([ShiftUserApproved::class, ShiftUserRejected::class]);

        $member = $this->createMember('99118');
        $shiftUser = ShiftUser::create([
            'shift_id' => $this->shift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
            'confirmed_at' => now(),
        ]);
        CheckIn::create([
            'shift_id' => $this->shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $member->id,
            'check_in_time' => '2026-05-25 09:00:00',
        ]);

        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempRejectedIds: [$member->id])
        );

        $response->assertOk();
        $this->assertDatabaseMissing('check_ins', ['shift_id' => $this->shift->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('shift_users', [
            'id' => $shiftUser->id,
            'status' => ShiftUserStatus::Rejected->value,
            'confirmed_at' => null,
        ]);
        Event::assertDispatched(ShiftUserRejected::class);
    }

    public function testUpdatingShiftPersistsTotalsAndBreakMinutes(): void
    {
        $payload = $this->buildPayload(fixedTotal: 2, tempTotal: 3) + [
            'max_registered_temp' => 7,
            'break_minutes' => 45,
        ];

        $response = $this->actingAs($this->manager, 'api')->putJson("/manage/shifts/{$this->shift->id}", $payload);

        $response->assertOk();
        $this->assertDatabaseHas('shifts', [
            'id' => $this->shift->id,
            'fixed_total' => 2,
            'temp_total' => 3,
            'max_registered_temp' => 7,
            'break_minutes' => 45,
        ]);
    }

    private function assertApproveFailsWith(User $member, string $errorKey): void
    {
        $response = $this->actingAs($this->manager, 'api')->putJson(
            "/manage/shifts/{$this->shift->id}",
            $this->buildPayload(tempApprovedIds: [$this->workplacePosition->id => [$member->id]])
        );

        $response->assertStatus(422);
        $response->assertJsonPath("data.{$errorKey}", [(string) $member->id]);
    }

    private function lockAccountAfterFirstRead(User $member): void
    {
        $locked = false;
        User::retrieved(function (User $retrieved) use ($member, &$locked) {
            if ($locked || $retrieved->id !== $member->id) {
                return;
            }
            $locked = true;
            DB::table('users')
                ->where('id', $member->id)
                ->update(['is_disabled' => true]);
        });
    }

    /**
     * Creates a workplace member whose papers are all valid, so only the trait under
     * test decides whether assignment succeeds.
     */
    private function createMember(string $staffNo): User
    {
        /** @var User $member */
        $member = User::factory()->create(['staff_no' => $staffNo]);
        $member->info->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);

        return $member->refresh();
    }

    /**
     * Books the member on another approved shift, which is what the work-hour rules
     * (overlap, rest time, daily shifts, weekly hours) are evaluated against.
     */
    private function approveOnShift(User $member, string $date, string $startTime, string $endTime): void
    {
        $otherWorkplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $otherShift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $otherWorkplaceShift->id,
            'creator_id' => $this->manager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $otherShift->id,
            'user_id' => $member->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }

    private function buildPayload(
        array $tempPendingIds = [],
        array $tempRejectedIds = [],
        array $tempApprovedIds = [],
        array $fixedPendingIds = [],
        array $fixedRejectedIds = [],
        array $fixedApprovedIds = [],
        int $fixedTotal = 0,
        int $tempTotal = 5
    ): array {
        return [
            'fixed_total' => $fixedTotal,
            'temp_total' => $tempTotal,
            'fixed' => [
                'pending_ids' => $fixedPendingIds,
                'rejected_ids' => $fixedRejectedIds,
                'approved_ids' => $fixedApprovedIds,
            ],
            'temp' => [
                'pending_ids' => $tempPendingIds,
                'rejected_ids' => $tempRejectedIds,
                'approved_ids' => $tempApprovedIds,
            ],
        ];
    }
}
