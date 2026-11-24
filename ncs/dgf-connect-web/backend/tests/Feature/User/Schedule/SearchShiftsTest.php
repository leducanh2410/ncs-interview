<?php

namespace Tests\Feature\User\Schedule;

use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchShiftsTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_DATE = '2026-05-10';

    private User $creator;

    private User $member;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');

        $this->creator = User::factory()->create();
        $this->member = $this->createRegisterableUser();

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
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->workplacePosition = WorkplacePosition::create([
            'workplace_id' => $this->workplace->id,
            'name' => 'Staff',
        ]);

        WorkplaceUser::create(['user_id' => $this->member->id, 'workplace_id' => $this->workplace->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * (fixed_total, temp_total, max_registered_temp, registered temp rows as [status, ...]) => visible?
     *
     * "Registered" means pending + approved temp rows; rejected rows and fixed rows never take a slot.
     *
     * @return array<string, array{int, int, int|null, array<int, string>, bool}>
     */
    public static function capacityCases(): array
    {
        $pending = ShiftUserStatus::Pending->value;
        $approved = ShiftUserStatus::Approved->value;
        $rejected = ShiftUserStatus::Rejected->value;

        return [
            'no staff needed at all (0/0/null)' => [0, 0, null, [], false],
            'no staff needed and cap 0 (0/0/0)' => [0, 0, 0, [], false],
            'temp needed, no cap, nobody registered' => [0, 2, null, [], true],
            'temp needed, no cap, full by approved' => [0, 2, null, [$approved, $approved], false],
            'temp needed, no cap, full by pending' => [0, 2, null, [$pending, $pending], false],
            'temp needed, no cap, full by pending + approved' => [0, 2, null, [$pending, $approved], false],
            'temp needed, no cap, rejected rows do not count' => [0, 2, null, [$rejected, $rejected], true],
            'temp needed, no cap, one slot left' => [0, 2, null, [$approved], true],
            'cap 0 always hides even with temp headcount' => [1, 3, 0, [], false],
            'cap set, slot left' => [0, 3, 2, [$approved], true],
            'cap set, full by approved' => [0, 3, 2, [$approved, $approved], false],
            'cap set, full by pending' => [0, 3, 2, [$pending, $pending], false],
            'cap set, full by pending + approved' => [0, 3, 2, [$pending, $approved], false],
            'cap set, rejected rows do not count' => [0, 3, 1, [$rejected], true],
            'cap larger than temp headcount still gates by cap' => [0, 1, 5, [$approved], true],
            'only fixed headcount, no cap => temp_total 0 means no slot' => [2, 0, null, [], false],
            'only fixed headcount but explicit cap allows registration' => [2, 0, 1, [], true],
        ];
    }

    #[DataProvider('capacityCases')]
    public function testShiftVisibilityFollowsRemainingTempSlots(
        int $fixedTotal,
        int $tempTotal,
        ?int $maxRegisteredTemp,
        array $registeredStatuses,
        bool $expectedVisible
    ): void {
        $shift = $this->createShift(self::SEARCH_DATE, [
            'fixed_total' => $fixedTotal,
            'temp_total' => $tempTotal,
            'max_registered_temp' => $maxRegisteredTemp,
        ]);
        foreach ($registeredStatuses as $status) {
            $this->createShiftUser($shift, User::factory()->create(), $status);
        }

        $response = $this->search();

        $response->assertOk();
        $this->assertSame($expectedVisible ? [$shift->id] : [], $this->visibleIds($response->json('data')));
    }

    public function testFixedRowsNeverConsumeATempSlot(): void
    {
        $shift = $this->createShift(self::SEARCH_DATE, ['fixed_total' => 2, 'temp_total' => 1]);
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value, isFixed: true);
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value, isFixed: true);

        $this->assertSame([$shift->id], $this->visibleIds($this->search()->json('data')));
    }

    public function testFullCheckAppliesToShiftsThatHaveNotStartedYet(): void
    {
        // Search date is two days ahead of "now": the old rule skipped the full check for
        // shifts that had not started, which is exactly how sold-out shifts kept showing up.
        $shift = $this->createShift(self::SEARCH_DATE, ['temp_total' => 1, 'max_registered_temp' => 1]);
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Pending->value);

        $this->assertSame([], $this->visibleIds($this->search()->json('data')));
    }

    public function testStartedButNotEndedShiftWithASlotLeftIsStillVisible(): void
    {
        // now is 2026-05-08 10:00, shift runs 09:00-18:00 the same day.
        $shift = $this->createShift('2026-05-08', ['temp_total' => 2]);
        $this->createShiftUser($shift, User::factory()->create(), ShiftUserStatus::Approved->value);

        $this->assertSame([$shift->id], $this->visibleIds($this->search('2026-05-08')->json('data')));
    }

    public function testEndedShiftIsHiddenEvenWithSlotsLeft(): void
    {
        $this->createShift('2026-05-07', ['temp_total' => 5]);

        $this->assertSame([], $this->visibleIds($this->search('2026-05-07')->json('data')));
    }

    public function testShiftTheUserAlreadyHasARowOnIsHidden(): void
    {
        $shift = $this->createShift(self::SEARCH_DATE, ['temp_total' => 5]);
        $this->createShiftUser($shift, $this->member, ShiftUserStatus::Rejected->value);

        $this->assertSame([], $this->visibleIds($this->search()->json('data')));
    }

    public function testShiftOutsideTheUsersWorkplacesIsHidden(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other',
            'address' => 'Elsewhere',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $otherWorkplaceShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->createShift(self::SEARCH_DATE, [
            'workplace_id' => $otherWorkplace->id,
            'workplace_shift_id' => $otherWorkplaceShift->id,
            'temp_total' => 5,
        ]);

        $this->assertSame([], $this->visibleIds($this->search()->json('data')));
    }

    public function testListAndRegisterAgreeOnceTheLastSlotIsTakenByAPendingRegistration(): void
    {
        $shift = $this->createShift(self::SEARCH_DATE, ['temp_total' => 3, 'max_registered_temp' => 1]);
        $firstComer = $this->createRegisterableUser();
        WorkplaceUser::create(['user_id' => $firstComer->id, 'workplace_id' => $this->workplace->id]);

        // Both members see the shift while the slot is open.
        $this->assertSame([$shift->id], $this->visibleIds($this->search()->json('data')));

        $this->actingAs($firstComer, 'api')
            ->postJson("/user/shifts/{$shift->id}/register")
            ->assertOk();

        // The pending registration takes the only slot: the list drops the shift for the
        // other member and the register endpoint refuses them for the same reason.
        $this->assertSame([], $this->visibleIds($this->search()->json('data')));
        $this->actingAs($this->member, 'api')
            ->postJson("/user/shifts/{$shift->id}/register")
            ->assertStatus(400)
            ->assertJsonPath('error', ErrorCode::ScheduleShiftMaxRegisteredTemp->value);
    }

    public function testResponseDoesNotLeakTheInternalRegisteredCount(): void
    {
        $shift = $this->createShift(self::SEARCH_DATE, ['temp_total' => 5]);

        $response = $this->search();

        $response->assertOk();
        $this->assertSame([$shift->id], $this->visibleIds($response->json('data')));
        $this->assertArrayNotHasKey('registered_temp_users_count', $response->json('data.0'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function search(string $date = self::SEARCH_DATE)
    {
        return $this->actingAs($this->member, 'api')->getJson('/user/shifts/search?date=' . $date);
    }

    /**
     * @return array<int, int>
     */
    private function visibleIds(array $data): array
    {
        return array_values(array_map(fn(array $shift) => $shift['id'], $data));
    }

    private function createRegisterableUser(): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'STAFF-' . fake()->unique()->numberBetween(1000, 9999),
        ]);

        $user->info()->update([
            'residence_expiry_date_1' => null,
            'residence_expiry_date_2' => null,
            'residence_expiry_date_3' => null,
            'passport_expiry_date' => null,
            'student_card_expiry_date' => null,
        ]);

        return $user->load('info');
    }

    private function createShift(string $date, array $attributes = []): Shift
    {
        return Shift::create(
            array_merge(
                [
                    'workplace_id' => $this->workplace->id,
                    'workplace_shift_id' => $this->workplaceShift->id,
                    'creator_id' => $this->creator->id,
                    'date' => $date,
                    'adjusted_date' => $date,
                    'temp_total' => 0,
                    'fixed_total' => 0,
                ],
                $attributes
            )
        );
    }

    private function createShiftUser(Shift $shift, User $user, string $status, bool $isFixed = false): ShiftUser
    {
        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
            'is_fixed' => $isFixed,
        ]);
    }
}
