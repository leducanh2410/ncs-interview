<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleDetail;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftDetailShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function testShiftDetailShowsShortenedNameForSriLankaCreatorShiftUserAndFixedScheduleUser(): void
    {
        Carbon::setTestNow('2026-05-08 10:00:00');

        $admin = User::factory()->create();
        $admin
            ->permissions()
            ->attach(Permission::create(['name' => PermissionName::ShiftList->value, 'display_name' => 'Shift List']));

        $creator = $this->sriLankaUser('CREATOR RAVISHANN');
        $shiftUserUser = $this->sriLankaUser('SHIFTUSER RAVISHANN');
        $fixedUser = $this->sriLankaUser('FIXEDUSER RAVISHANN');

        $workplace = Workplace::create([
            'name' => 'Test Workplace',
            'address' => 'Test Address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $creator->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $shiftUserUser->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        $fixedSchedule = FixedSchedule::create(['user_id' => $fixedUser->id, 'workplace_id' => $workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $fixedSchedule->id,
            'workplace_shift_id' => $workplaceShift->id,
            'day' => Carbon::parse($shift->date)->dayOfWeek,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/manage/shifts/{$shift->id}");

        $response->assertOk();
        $response->assertJsonPath('data.shift.creator.name', 'CREATOR RAVISHANN');
        $shiftUsers = collect($response->json('data.shift_users'))->keyBy('user_id');
        $this->assertEquals('SHIFTUSER RAVISHANN', $shiftUsers[$shiftUserUser->id]['user']['name']);
        $fixedScheduleUsers = collect($response->json('data.fixed_schedule_users'))->keyBy('id');
        $this->assertEquals('FIXEDUSER RAVISHANN', $fixedScheduleUsers[$fixedUser->id]['name']);

        Carbon::setTestNow();
    }

    private function sriLankaUser(string $secondGivenName): User
    {
        $user = User::factory()->create([
            'first_name' => "Ragalage {$secondGivenName}",
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        return $user;
    }
}
