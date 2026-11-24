<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceShiftLeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplaceListShiftsShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function testListShiftsReturnsShortenedNameForSriLankaLeader(): void
    {
        $admin = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['name' => PermissionName::WorkplaceShiftList->value],
            ['display_name' => 'Workplace Shift List']
        );
        $admin->permissions()->attach($permission);

        $leader = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $leader->info->update(['nationality' => Nationality::SriLanka->value]);

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
            'is_active' => true,
        ]);
        WorkplaceShiftLeader::create([
            'workplace_shift_id' => $workplaceShift->id,
            'user_id' => $leader->id,
            'bonus_per_hour' => 100,
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/workplaces/{$workplace->id}/shifts");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $leaders = collect($items->firstWhere('id', $workplaceShift->id)['leaders']);
        $this->assertEquals('THARINDU RAVISHANN', $leaders->firstWhere('user_id', $leader->id)['user']['name']);
    }
}
