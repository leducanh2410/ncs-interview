<?php

namespace Tests\Feature\User\Schedule;

use App\Actions\User\Schedule\RegisterShiftAction;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
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

class RegisterShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private WorkplacePosition $workplacePosition;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-08 10:00:00');
        config(['absence.window_days' => 180]);

        $this->creator = User::factory()->create();

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

    public function testSuccessfulRegistrationResponseIncludesAbsenceCount(): void
    {
        $user = $this->createRegisterableUser();
        $shift = $this->createShift('2026-05-10');
        $this->createShiftUser($user, '2026-05-05');

        $response = $this->actingAs($user, 'api')->postJson("/user/shifts/{$shift->id}/register");

        $response->assertOk();
        $response->assertJsonPath('data.absence_count', 1);
    }

    public function testRegistrationFailureResponseOmitsAbsenceCount(): void
    {
        $user = $this->createRegisterableUser();
        $shift = $this->createShift('2026-05-10', ['max_registered_temp' => 0]);
        $this->createShiftUser($user, '2026-05-05');

        $response = $this->actingAs($user, 'api')->postJson("/user/shifts/{$shift->id}/register");

        $response->assertBadRequest();
        $this->assertArrayNotHasKey('absence_count', $response->json('data') ?? []);
    }

    public function testJustRegisteredShiftDoesNotContributeToAbsenceCount(): void
    {
        $user = $this->createRegisterableUser();
        $shift = $this->createShift('2026-05-10');

        $response = $this->actingAs($user, 'api')->postJson("/user/shifts/{$shift->id}/register");

        $response->assertOk();
        $response->assertJsonPath('data.absence_count', 0);
    }

    public function testRegistrationRejectsUserLockedAfterTheRequestsUserSnapshot(): void
    {
        $user = $this->createRegisterableUser();
        $shift = $this->createShift('2026-05-10');

        $this->actingAs($user, 'api');

        DB::table('users')
            ->where('id', $user->id)
            ->update(['is_disabled' => true]);

        $result = app(RegisterShiftAction::class)($shift);

        $this->assertSame(ErrorCode::ScheduleUserDisabled, $result->getError());
        $this->assertDatabaseMissing('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $user->id,
        ]);
    }

    public function testRegistrationSucceedsWhenUserStaysActive(): void
    {
        $user = $this->createRegisterableUser();
        $shift = $this->createShift('2026-05-10');

        $this->actingAs($user, 'api');

        $result = app(RegisterShiftAction::class)($shift);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('shift_users', [
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => ShiftUserStatus::Pending->value,
        ]);
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

    private function createShiftUser(
        User $user,
        string $date,
        string $status = ShiftUserStatus::Approved->value
    ): ShiftUser {
        return ShiftUser::create([
            'shift_id' => $this->createShift($date)->id,
            'user_id' => $user->id,
            'workplace_position_id' => $this->workplacePosition->id,
            'status' => $status,
        ]);
    }
}
