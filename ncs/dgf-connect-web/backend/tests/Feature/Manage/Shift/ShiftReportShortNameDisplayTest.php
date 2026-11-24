<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftReportShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        $permission = Permission::create([
            'name' => PermissionName::WorkReportList->value,
            'display_name' => 'Work Report List',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->manager = User::factory()->create();
        $this->manager->addRole($role);
    }

    public function testShiftReportListReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();
        $context = $this->createShiftContext($user, '2026-03-10');

        ShiftReport::create([
            'shift_user_id' => $context['shiftUser']->id,
            'user_id' => $user->id,
            'date' => '2026-03-10',
            'late_minutes' => 5,
            'early_minutes' => null,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson(
            '/manage/reports?dates[]=2026-03-01&dates[]=2026-03-31'
        );

        $response->assertStatus(200);
        $items = collect($response->json('data.data'));
        $this->assertEquals('THARINDU RAVISHANN', $items->firstWhere('user_id', $user->id)['user']['name']);
    }

    public function testShiftReportMonthlyReturnsShortenedNameForSriLankaUser(): void
    {
        $user = $this->sriLankaUser();
        $context = $this->createShiftContext($user, '2026-03-10');

        ShiftReport::create([
            'shift_user_id' => $context['shiftUser']->id,
            'user_id' => $user->id,
            'date' => '2026-03-10',
            'late_minutes' => 5,
            'early_minutes' => null,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->manager, 'api')->getJson('/manage/reports/monthly?month=2026-03');

        $response->assertStatus(200);
        $items = collect($response->json('data.data'));
        $this->assertEquals('THARINDU RAVISHANN', $items->firstWhere('id', $user->id)['name']);
    }

    private function sriLankaUser(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        return $user;
    }

    private function createShiftContext(User $user, string $date): array
    {
        $workplace = Workplace::create([
            'name' => 'Workplace ' . $user->id,
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

        $position = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return [
            'workplace' => $workplace,
            'shiftUser' => $shiftUser,
        ];
    }
}
