<?php

namespace Tests\Feature\Manage\Shift;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Exports\ShiftExport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ExportShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 20:00:00');

        $shiftList = Permission::create([
            'name' => PermissionName::ShiftList->value,
            'display_name' => 'Shift List',
        ]);
        $shiftExport = Permission::create([
            'name' => PermissionName::ShiftExport->value,
            'display_name' => 'Shift Export',
        ]);

        $role = Role::create(['name' => RoleName::GlobalAdmin->value, 'display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching([$shiftList->id, $shiftExport->id]);

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

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
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $this->shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->admin->id,
            'date' => '2026-05-10',
            'adjusted_date' => '2026-05-10',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testExportDownloadsTheTimesheet(): void
    {
        Excel::fake();

        $response = $this->actingAs($this->admin, 'api')->get("/manage/shifts/{$this->shift->id}/export");

        $response->assertOk();
        Excel::assertDownloaded("shift_{$this->shift->id}.xlsx", function (ShiftExport $export) {
            return $export instanceof ShiftExport;
        });
    }

    public function testExportForbiddenWithoutPermission(): void
    {
        /** @var User $other */
        $other = User::factory()->create();

        $response = $this->actingAs($other, 'api')->get("/manage/shifts/{$this->shift->id}/export");

        $response->assertStatus(403);
    }
}
