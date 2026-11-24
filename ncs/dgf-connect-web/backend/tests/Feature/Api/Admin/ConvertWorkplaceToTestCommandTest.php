<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ShiftUserStatus;
use App\Models\FixedSchedule;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConvertWorkplaceToTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testConvertsSpecifiedUsersToTestAndRemovesOtherNonTestMembers(): void
    {
        $workplace = Workplace::factory()->create(['name' => 'Real Site', 'is_test' => false]);

        $keepA = User::factory()->create(['is_test' => false]);
        $keepB = User::factory()->create(['is_test' => false]);
        $removeC = User::factory()->create(['is_test' => false]);
        $alreadyTest = User::factory()->create(['is_test' => true]);

        $workplace->users()->attach([$keepA->id, $keepB->id, $removeC->id, $alreadyTest->id]);

        $this->createFutureShift($removeC, $workplace);
        FixedSchedule::query()->create([
            'user_id' => $removeC->id,
            'workplace_id' => $workplace->id,
        ]);

        $this->artisan('workplaces:convert-to-test', [
            'workplace' => $workplace->id,
            'userIds' => [$keepA->id, $keepB->id],
        ])
            ->expectsConfirmation('Do you want to proceed with the conversion?', 'yes')
            ->assertExitCode(0);

        $this->assertTrue((bool) $workplace->fresh()->is_test);

        $this->assertTrue((bool) $keepA->fresh()->is_test);
        $this->assertTrue((bool) $keepB->fresh()->is_test);
        $this->assertFalse((bool) $removeC->fresh()->is_test);

        $this->assertDatabaseHas('workplace_users', ['workplace_id' => $workplace->id, 'user_id' => $keepA->id]);
        $this->assertDatabaseHas('workplace_users', ['workplace_id' => $workplace->id, 'user_id' => $keepB->id]);
        $this->assertDatabaseHas('workplace_users', ['workplace_id' => $workplace->id, 'user_id' => $alreadyTest->id]);
        $this->assertDatabaseMissing('workplace_users', ['workplace_id' => $workplace->id, 'user_id' => $removeC->id]);

        $this->assertSame(0, ShiftUser::query()->where('user_id', $removeC->id)->count());
        $this->assertSame(0, FixedSchedule::query()->where('user_id', $removeC->id)->count());
    }

    public function testDryRunMakesNoChanges(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);
        $keep = User::factory()->create(['is_test' => false]);
        $workplace->users()->attach($keep->id);

        $this->artisan('workplaces:convert-to-test', [
            'workplace' => $workplace->id,
            'userIds' => [$keep->id],
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertFalse((bool) $workplace->fresh()->is_test);
        $this->assertFalse((bool) $keep->fresh()->is_test);
        $this->assertDatabaseHas('workplace_users', ['workplace_id' => $workplace->id, 'user_id' => $keep->id]);
    }

    public function testFailsWhenWorkplaceAlreadyTest(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => true]);
        $user = User::factory()->create();

        $this->artisan('workplaces:convert-to-test', [
            'workplace' => $workplace->id,
            'userIds' => [$user->id],
        ])->assertExitCode(1);
    }

    public function testFailsWhenWorkplaceNotFound(): void
    {
        $user = User::factory()->create();

        $this->artisan('workplaces:convert-to-test', [
            'workplace' => 9999,
            'userIds' => [$user->id],
        ])->assertExitCode(1);
    }

    public function testFailsWhenSpecifiedUserDoesNotExist(): void
    {
        $workplace = Workplace::factory()->create(['is_test' => false]);
        $existing = User::factory()->create();

        $this->artisan('workplaces:convert-to-test', [
            'workplace' => $workplace->id,
            'userIds' => [$existing->id, 8888],
        ])->assertExitCode(1);

        $this->assertFalse((bool) $workplace->fresh()->is_test);
        $this->assertFalse((bool) $existing->fresh()->is_test);
    }

    private function createFutureShift(User $user, Workplace $workplace): ShiftUser
    {
        $position = WorkplacePosition::query()->create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $date = Carbon::today()->addDays(7)->toDateString();

        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => false,
        ]);
    }
}
