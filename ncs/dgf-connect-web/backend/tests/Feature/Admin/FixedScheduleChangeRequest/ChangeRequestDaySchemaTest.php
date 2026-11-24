<?php

namespace Tests\Feature\Admin\FixedScheduleChangeRequest;

use App\Enums\FixedScheduleChangeRequestStatus;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeRequestDaySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function testDeletingAShiftStillReferencedAsARequestedShiftIsBlocked(): void
    {
        $workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $shift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $employee = User::factory()->create();
        $schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $workplace->id,
            'status' => FixedScheduleChangeRequestStatus::Pending->value,
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => null,
            'requested_workplace_shift_id' => $shift->id,
        ]);

        // Deleting a shift that a pending request still asks to switch to must not silently
        // turn the requested change into null (which approval would read as "day off").
        $this->expectException(QueryException::class);
        $shift->delete();
    }
}
