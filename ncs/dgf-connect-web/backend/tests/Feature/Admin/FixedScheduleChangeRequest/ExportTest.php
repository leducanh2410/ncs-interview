<?php

namespace Tests\Feature\Admin\FixedScheduleChangeRequest;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Exports\FixedScheduleChangeRequestExport;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private Workplace $workplace;

    private WorkplaceShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->shift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
    }

    public function testExportsFilteredResultsAsXlsx(): void
    {
        Excel::fake();

        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );

        $employee = User::factory()->create();
        $schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => 'pending',
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shift->id,
            'requested_workplace_shift_id' => null,
        ]);

        $response = $this->actingAs($manager, 'api')->get('/admin/fixed-schedule-change-requests/export');

        $response->assertOk();
        Excel::assertDownloaded('fixed_schedule_change_requests.xlsx', function (
            FixedScheduleChangeRequestExport $export
        ) {
            return $export->collection()->count() === 1;
        });
    }

    public function testExportedRowUsesShortenedNameForSriLankaEmployee(): void
    {
        $manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $manager,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );

        $employee = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $employee->info->update(['nationality' => Nationality::SriLanka->value]);
        $schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $this->workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $this->workplace->id,
            'status' => 'pending',
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shift->id,
            'requested_workplace_shift_id' => null,
        ]);

        $export = new FixedScheduleChangeRequestExport(collect([$request->fresh(['user', 'workplace', 'days'])]));

        $this->assertSame('THARINDU RAVISHANN', $export->map($request)[0]);
    }

    public function testRequiresListPermission(): void
    {
        $manager = User::factory()->create();

        $response = $this->actingAs($manager, 'api')->get('/admin/fixed-schedule-change-requests/export');

        $response->assertForbidden();
    }
}
