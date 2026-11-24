<?php

namespace Tests\Concerns;

use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;

trait SeedsReconciliationFixtures
{
    use GrantsWorkplacePermissions;

    protected function makeReconciliationAdmin(): User
    {
        $admin = User::factory()->create();
        $this->giveGlobalPermission($admin, PermissionName::ReconciliationWork);

        return $admin;
    }

    protected function makeWorkplace(string $name = 'Workplace A'): Workplace
    {
        return Workplace::create([
            'name' => $name,
            'address' => 'Addr',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
    }

    protected function makeEmployeeWithCheckIn(
        Workplace $workplace,
        array $userAttrs,
        string $date = '2026-08-25',
        array $checkInAttrs = [],
        ?string $adjustedDate = null,
        bool $absent = false
    ): array {
        $employee = User::factory()->create($userAttrs);
        WorkplaceUser::create(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);

        $template = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '21:00:00',
            'end_time' => '06:00:00',
            'break_duration' => 60,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        // (workplace_id, name, active) is unique, so seeding a second employee
        // at the same workplace must reuse the position rather than duplicate it.
        $position = WorkplacePosition::firstOrCreate(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $creator = User::factory()->create();
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $template->id,
            'creator_id' => $creator->id,
            'date' => $date,
            'adjusted_date' => $adjustedDate ?? $date,
            'fixed_total' => 5,
            'temp_total' => 5,
        ]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
            'is_absent' => $absent,
        ]);
        foreach (['check_in_time', 'check_out_time'] as $column) {
            if (array_key_exists($column, $checkInAttrs) && !array_key_exists("{$column}_rounded", $checkInAttrs)) {
                $checkInAttrs["{$column}_rounded"] = $checkInAttrs[$column];
            }
        }
        $checkIn = CheckIn::create(
            array_merge(
                [
                    'shift_id' => $shift->id,
                    'shift_user_id' => $shiftUser->id,
                    'user_id' => $employee->id,
                    'check_in_time' => $date . ' 20:15:00',
                    'check_out_time' => date('Y-m-d', strtotime($date . ' +1 day')) . ' 06:00:00',
                    'check_in_time_rounded' => $date . ' 21:00:00',
                    'check_out_time_rounded' => date('Y-m-d', strtotime($date . ' +1 day')) . ' 06:00:00',
                    'break_minutes' => 60,
                    'work_minutes' => 480,
                    'work_minutes_normal' => 120,
                    'work_minutes_night' => 360,
                    'work_minutes_overtime' => 0,
                    'work_minutes_night_overtime' => 0,
                ],
                $checkInAttrs
            )
        );

        return compact('employee', 'shift', 'shiftUser', 'checkIn', 'template', 'position');
    }
}
