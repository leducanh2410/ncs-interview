<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\GroupKind;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');
        config(['absence.window_days' => 180]);

        $this->admin = User::factory()->create();

        $permission = Permission::create([
            'name' => PermissionName::ShiftEdit->value,
            'display_name' => 'Shift Edit',
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

    public function testSearchResultIncludesAbsenceCountPerUser(): void
    {
        $userWithAbsence = $this->createSearchableUser('ABS-001');
        $userWithoutAbsence = $this->createSearchableUser('ABS-002');
        $this->createPriorAbsence($userWithAbsence, '2026-05-05');

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&q=ABS"
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $userWithAbsence->id);
        $response->assertJsonPath('data.0.absence_count', 1);
        $response->assertJsonPath('data.1.id', $userWithoutAbsence->id);
        $response->assertJsonPath('data.1.absence_count', 0);
    }

    public function testSearchResultIncludesSimilarShiftWorkedCount(): void
    {
        $candidate = $this->createSearchableUser('SIM-001');

        // Two checked-in shifts at a similar time range, both inside the 90-day window.
        $this->createWorkedShift($this->workplaceShift, $candidate, '2026-05-01');
        $similarShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:30:00',
            'end_time' => '18:30:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->createWorkedShift($similarShift, $candidate, '2026-04-01');

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&workplace_shift_id={$this->workplaceShift->id}&q=SIM"
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $candidate->id);
        $response->assertJsonPath('data.0.similar_shift_count', 2);
    }

    public function testSimilarShiftCountIsNullWithoutWorkplaceShiftId(): void
    {
        $candidate = $this->createSearchableUser('SIM-001');
        $this->createWorkedShift($this->workplaceShift, $candidate, '2026-05-01');

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&q=SIM"
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.similar_shift_count', null);
    }

    public function testSearchUsesSingleAbsenceQueryRegardlessOfResultCount(): void
    {
        $users = collect([
            $this->createSearchableUser('ABS-001'),
            $this->createSearchableUser('ABS-002'),
            $this->createSearchableUser('ABS-003'),
            $this->createSearchableUser('ABS-004'),
        ]);

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

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&q=ABS"
        );

        $response->assertOk();

        $absenceQueries = collect(DB::getQueryLog())->filter(
            fn(array $query) => str_contains($query['query'], 'absence_count')
        );

        $this->assertCount(1, $absenceQueries);

        DB::disableQueryLog();
    }

    public function testSearchByGroupIdsOnlyReturnsMembersOfAllSelectedGroups(): void
    {
        $userInBothGroups = $this->createSearchableUser('GRP-001');
        $userInOneGroup = $this->createSearchableUser('GRP-002');
        $userInNoGroup = $this->createSearchableUser('GRP-003');

        $groupA = Group::query()->create([
            'kind' => GroupKind::Manual,
            'name' => 'Group A',
            'identity_key' => 'manual_group_a',
        ]);
        $groupB = Group::query()->create([
            'kind' => GroupKind::Manual,
            'name' => 'Group B',
            'identity_key' => 'manual_group_b',
        ]);

        $userInBothGroups->groups()->attach($groupA);
        $userInBothGroups->groups()->attach($groupB);
        $userInOneGroup->groups()->attach($groupA);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&group_ids[]={$groupA->id}&group_ids[]={$groupB->id}"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $userInBothGroups->id);
    }

    public function testSearchByKeywordAndGroupIdsReturnsIntersection(): void
    {
        $userMatch = $this->createSearchableUser('KEY-001');
        $userNoMatch = $this->createSearchableUser('KEY-002');

        $group = Group::query()->create([
            'kind' => GroupKind::Manual,
            'name' => 'Group Key',
            'identity_key' => 'manual_group_key',
        ]);

        $userMatch->groups()->attach($group);
        $userNoMatch->groups()->attach($group);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&q=KEY-001&group_ids[]={$group->id}"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $userMatch->id);
    }

    public function testSearchByStaffNoSucceeds(): void
    {
        $user = $this->createSearchableUser('SNO-001');

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}&staff_no=SNO-001&q=SNO"
        );

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($user->id));
    }

    public function testSearchRequiresKeywordOrGroupIds(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/manage/shifts/users/search?workplace_id={$this->workplace->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.q.0', 'Please enter a search keyword or select at least one group.');
    }

    private function createWorkedShift(WorkplaceShift $workplaceShift, User $user, string $date): void
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        CheckIn::create([
            'shift_user_id' => $shiftUser->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'check_in_time' => $date . ' 09:05:00',
        ]);
    }

    private function createSearchableUser(string $staffNo): User
    {
        $user = User::factory()->create(['staff_no' => $staffNo]);
        $user->workplaces()->attach($this->workplace);

        return $user;
    }

    private function createPriorAbsence(User $user, string $date): ShiftUser
    {
        $shift = Shift::create([
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);
    }
}
