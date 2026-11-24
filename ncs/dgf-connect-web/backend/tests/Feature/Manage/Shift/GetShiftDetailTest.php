<?php

namespace Tests\Feature\Manage\Shift;

use App\Actions\Admin\User\RequestDocumentCompletionAction;
use App\Actions\User\Document\ResolveRequiredStepKeysAction;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\PermissionName;
use App\Enums\ResidenceStatus;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperStepKey;
use App\Enums\UserPaperType;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ResetsUserDocumentInfo;
use Tests\TestCase;

class GetShiftDetailTest extends TestCase
{
    use RefreshDatabase;
    use ResetsUserDocumentInfo;

    private User $admin;

    private User $creator;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');
        config(['absence.window_days' => 180]);

        $this->admin = User::factory()->create();
        $this->creator = User::factory()->create();

        $permission = Permission::create([
            'name' => PermissionName::ShiftList->value,
            'display_name' => 'Shift List',
        ]);
        $this->admin->permissions()->attach($permission);

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDetailExposesOvertimeByDurationFlagAsBoolean(): void
    {
        $shift = $this->createShift('2026-05-10');
        $shift->update(['is_overtime_by_duration' => true]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        // The shift config modal reads this straight off the detail payload.
        $response->assertJsonPath('data.shift.is_overtime_by_duration', true);
    }

    public function testDetailExposesShiftWorkHoursExcludingBreak(): void
    {
        $shift = $this->createShift('2026-05-10');
        $shift->update(['break_minutes' => 60]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $this->assertEqualsWithDelta(8.0, $response->json('data.shift.work_hours'), 0.01);
    }

    public function testEveryUserInEveryGroupHasAbsenceCount(): void
    {
        $shift = $this->createShift('2026-05-10');
        $pendingUser = User::factory()->create();
        $rejectedUser = User::factory()->create();
        $approvedUser = User::factory()->create();
        $fixedScheduleUser = User::factory()->create();

        $this->createShiftUser($shift, $pendingUser, ShiftUserStatus::Pending->value);
        $this->createShiftUser($shift, $rejectedUser, ShiftUserStatus::Rejected->value);
        $this->createShiftUser($shift, $approvedUser, ShiftUserStatus::Approved->value);
        $this->createFixedSchedule($fixedScheduleUser, Carbon::parse($shift->date)->dayOfWeek);
        $this->createPriorAbsence($approvedUser, '2026-05-05');

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();

        foreach ($response->json('data.shift_users') as $shiftUser) {
            $this->assertArrayHasKey('absence_count', $shiftUser['user']);
            $this->assertIsInt($shiftUser['user']['absence_count']);
        }

        foreach ($response->json('data.fixed_schedule_users') as $fixedScheduleUser) {
            $this->assertArrayHasKey('absence_count', $fixedScheduleUser);
            $this->assertIsInt($fixedScheduleUser['absence_count']);
        }
    }

    public function testSameUserAcrossGroupsShowsSameCount(): void
    {
        $shift = $this->createShift('2026-05-10');
        $user = User::factory()->create();

        $this->createShiftUser($shift, $user, ShiftUserStatus::Pending->value);
        $this->createFixedSchedule($user, Carbon::parse($shift->date)->dayOfWeek);
        $this->createPriorAbsence($user, '2026-05-05');

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $response->assertJsonPath('data.shift_users.0.user.absence_count', 1);
        $response->assertJsonPath('data.fixed_schedule_users.0.absence_count', 1);
    }

    public function testEndpointMakesSingleAbsenceQueryRegardlessOfGroupCount(): void
    {
        $shift = $this->createShift('2026-05-10');
        $users = User::factory()->count(6)->create();

        $this->createShiftUser($shift, $users[0], ShiftUserStatus::Pending->value);
        $this->createShiftUser($shift, $users[1], ShiftUserStatus::Rejected->value);
        $this->createShiftUser($shift, $users[2], ShiftUserStatus::Approved->value);
        $this->createShiftUser($shift, $users[3], ShiftUserStatus::Approved->value);
        $this->createFixedSchedule($users[4], Carbon::parse($shift->date)->dayOfWeek);
        $this->createFixedSchedule($users[5], Carbon::parse($shift->date)->dayOfWeek);

        foreach ($users as $index => $user) {
            $this->createPriorAbsence(
                $user,
                Carbon::today()
                    ->subDays($index + 2)
                    ->toDateString()
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();

        $absenceQueries = collect(DB::getQueryLog())->filter(
            fn(array $query) => str_contains($query['query'], 'absence_count')
        );

        $this->assertCount(1, $absenceQueries);

        DB::disableQueryLog();
    }

    public function testShiftUsersExposeTheUrgentRegistrationFlag(): void
    {
        $shift = $this->createShift('2026-05-10');
        $urgent = User::factory()->create();
        $ordinary = User::factory()->create();

        $urgentShiftUser = $this->createShiftUser($shift, $urgent, ShiftUserStatus::Approved->value);
        $urgentShiftUser->forceFill(['is_urgent_registration' => true])->saveQuietly();
        $this->createShiftUser($shift, $ordinary, ShiftUserStatus::Approved->value);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();

        // The admin screen badges this flag, and it reaches the client only because this
        // query returns whole models rather than a column list. Narrowing that query with a
        // select() would make the badge silently disappear, so the contract is asserted here.
        $flags = collect($response->json('data.shift_users'))->pluck('is_urgent_registration', 'user_id')->all();

        $this->assertArrayHasKey($urgent->id, $flags);
        $this->assertArrayHasKey($ordinary->id, $flags);
        $this->assertTrue((bool) $flags[$urgent->id]);
        $this->assertFalse((bool) $flags[$ordinary->id]);
    }

    public function testHotBonusAmountsAreReportedOncePerShiftAndDeduplicated(): void
    {
        $shift = $this->createShift('2026-05-10');
        $otherShift = $this->createShift('2026-05-11');

        $this->createRecruitmentNotificationFor($shift, 3000);
        $this->createRecruitmentNotificationFor($shift, 5000);
        // A repeat of the same campaign amount should not be listed twice.
        $this->createRecruitmentNotificationFor($shift, 5000);
        // No bonus advertised — must not appear as a zero.
        $this->createRecruitmentNotificationFor($shift, null);
        // Belongs to a different shift entirely.
        $this->createRecruitmentNotificationFor($otherShift, 9999);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $this->assertSame([3000, 5000], $response->json('data.hot_bonus_amounts'));
    }

    public function testHotBonusAmountsAreEmptyForAShiftWithNoRecruitment(): void
    {
        $shift = $this->createShift('2026-05-10');

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('data.hot_bonus_amounts'));
    }

    public function testMembersSentBackForDocumentsExposeWhatTheyStillOwe(): void
    {
        $shift = $this->createShift('2026-05-10');
        $owesDocuments = $this->makeApprovedStudyAbroadUser(complete: false);
        $owesExpiryDate = $this->makeApprovedStudyAbroadUser(complete: true);
        $owesExpiryDate->info->update(['student_card_expiry_date' => null]);
        $complete = $this->makeApprovedStudyAbroadUser(complete: true);

        foreach ([$owesDocuments, $owesExpiryDate] as $user) {
            $this->assertNull(app(RequestDocumentCompletionAction::class)($user->refresh())->getError());
        }
        foreach ([$owesDocuments, $owesExpiryDate, $complete] as $user) {
            $this->createShiftUser($shift, $user, ShiftUserStatus::Approved->value);
        }

        $users = collect(
            $this->actingAs($this->admin, 'api')
                ->getJson("/manage/shifts/{$shift->id}")
                ->assertOk()
                ->json('data.shift_users')
        )
            ->pluck('user')
            ->keyBy('id');

        $this->assertSame(
            [UserPaperStepKey::StudentCard->value, UserPaperStepKey::WorkPermit->value],
            $users[$owesDocuments->id]['outstanding_document_steps']
        );
        $this->assertSame(
            [UserPaperStepKey::StudentCard->value],
            $users[$owesExpiryDate->id]['outstanding_document_steps']
        );
        $this->assertSame([], $users[$complete->id]['outstanding_document_steps']);
        $this->assertArrayNotHasKey('paper', $users[$owesDocuments->id]);
    }

    public function testARegisteredMemberWithoutAFileOwesEveryRequiredDocument(): void
    {
        $shift = $this->createShift('2026-05-10');
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Registered->value]);
        $this->resetUserDocumentInfo($user);
        $user->info->update(['residence_status_1' => ResidenceStatus::StudyAbroad->value]);
        $this->createFixedSchedule($user, Carbon::parse($shift->date)->dayOfWeek);

        $fixedScheduleUser = collect(
            $this->actingAs($this->admin, 'api')
                ->getJson("/manage/shifts/{$shift->id}")
                ->assertOk()
                ->json('data.fixed_schedule_users')
        )->firstWhere('id', $user->id);

        $this->assertSame(
            array_map(
                fn(UserPaperStepKey $key) => $key->value,
                app(ResolveRequiredStepKeysAction::class)(ResidenceStatus::StudyAbroad->value)
            ),
            $fixedScheduleUser['outstanding_document_steps']
        );
    }

    private function makeApprovedStudyAbroadUser(bool $complete): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'SN-' . fake()->unique()->numerify('#####'),
        ]);
        $this->resetUserDocumentInfo($user);
        $user->info->update([
            'residence_status_1' => ResidenceStatus::StudyAbroad->value,
            'residence_number_1' => 'AB' . fake()->unique()->numerify('########') . 'CD',
            'residence_expiry_date_1' => '2028-05-08',
            'student_card_expiry_date' => $complete ? '2027-03-31' : null,
        ]);

        $paper = UserPaper::factory()->create([
            'user_id' => $user->id,
            'approved_step_keys' => array_map(
                fn(UserPaperStepKey $key) => $key->value,
                app(ResolveRequiredStepKeysAction::class)(ResidenceStatus::StudyAbroad->value)
            ),
        ]);
        $types = [
            UserPaperType::ForeignerCardFront,
            UserPaperType::ForeignerCardBack,
            UserPaperType::Passport,
            UserPaperType::PortraitImage,
        ];
        if ($complete) {
            $types[] = UserPaperType::StudentCardFront;
            $types[] = UserPaperType::StudentCardBack;
            $types[] = UserPaperType::WorkPermitStamp;
        }

        foreach ($types as $type) {
            UserPaperItem::factory()->create([
                'user_paper_id' => $paper->id,
                'type' => $type->value,
                'approved_at' => now(),
                'rejected_at' => null,
                'deleted_at' => null,
                'folder' => UserPaperItemFolder::Current->value,
            ]);
        }

        return $user->fresh(['info', 'paper']);
    }

    private function createShift(string $date): Shift
    {
        return Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->creator->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    private function createRecruitmentNotificationFor(Shift $shift, ?int $hotBonus): Notification
    {
        return Notification::create([
            'type' => NotificationType::RECRUITMENT->value,
            'title' => 'Urgent staff needed',
            'content' => 'Please help cover this shift',
            'status' => NotificationStatus::SENT,
            'is_manually_scheduled' => false,
            'scheduled_at' => now(),
            'user_id' => null,
            'data' => [
                'shift_id' => $shift->id,
                'workplace_id' => $shift->workplace_id,
                'hot_bonus_amount' => $hotBonus,
            ],
        ]);
    }

    private function createShiftUser(Shift $shift, User $user, string $status): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
        ]);
    }

    private function createPriorAbsence(User $user, string $date): ShiftUser
    {
        return $this->createShiftUser($this->createShift($date), $user, ShiftUserStatus::Approved->value);
    }

    private function createFixedSchedule(User $user, int $day, ?WorkplacePosition $position = null): void
    {
        $fixedSchedule = FixedSchedule::create([
            'user_id' => $user->id,
            'workplace_id' => $this->workplace->id,
        ]);

        FixedScheduleDetail::create([
            'fixed_schedule_id' => $fixedSchedule->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'workplace_position_id' => $position?->id,
            'day' => $day,
        ]);
    }

    public function testFixedScheduleUsersExposeTheirFixedSchedulePosition(): void
    {
        $shift = $this->createShift('2026-05-10');
        $day = Carbon::parse($shift->date)->dayOfWeek;
        $userWithPosition = User::factory()->create();
        $userWithoutPosition = User::factory()->create();
        $this->createFixedSchedule($userWithPosition, $day, $this->workplacePosition);
        $this->createFixedSchedule($userWithoutPosition, $day);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $fixedUsers = collect($response->json('data.fixed_schedule_users'))->keyBy('id');
        $this->assertSame(
            ['id' => $this->workplacePosition->id, 'name' => 'Staff'],
            $fixedUsers[$userWithPosition->id]['fixed_schedule_position']
        );
        $this->assertNull($fixedUsers[$userWithoutPosition->id]['fixed_schedule_position']);
        $this->assertArrayNotHasKey('fixed_schedule_workplace_position_id', $fixedUsers[$userWithPosition->id]);
    }
}
