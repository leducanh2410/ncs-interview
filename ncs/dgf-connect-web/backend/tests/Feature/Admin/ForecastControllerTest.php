<?php

namespace Tests\Feature\Admin;

use App\Enums\AbsentNoticeStatus;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $globalAdmin;

    private Workplace $workplace;

    private WorkplaceShift $workplaceShift;

    private Permission $shiftListPermission;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-26 10:00:00');

        $this->shiftListPermission = Permission::create([
            'name' => PermissionName::ShiftList->value,
            'display_name' => 'Shift List',
        ]);

        $globalAdminRole = Role::create([
            'name' => RoleName::GlobalAdmin->value,
            'display_name' => 'Global Admin',
        ]);
        $globalAdminRole->permissions()->syncWithoutDetaching([$this->shiftListPermission->id]);

        $this->globalAdmin = User::factory()->create();
        $this->globalAdmin->addRole($globalAdminRole);

        $this->workplace = Workplace::create([
            'name' => 'Test Warehouse',
            'address' => '1-1 Tokyo',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $this->workplaceShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- GET /admin/forecast/options ---

    public function testOptionsRequiresAuthentication(): void
    {
        $this->getJson('/admin/forecast/options')->assertUnauthorized();
    }

    public function testOptionsRequiresShiftListPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson('/admin/forecast/options')->assertForbidden();
    }

    public function testOptionsReturnsWorkplacesWithActiveShifts(): void
    {
        $inactiveShift = WorkplaceShift::create([
            'workplace_id' => $this->workplace->id,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'is_active' => false,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);

        $response = $this->actingAs($this->globalAdmin, 'api')->getJson('/admin/forecast/options');

        $response->assertOk();
        $workplaces = $response->json('data.workplaces');
        $this->assertCount(1, $workplaces);
        $this->assertSame($this->workplace->id, $workplaces[0]['id']);

        $shiftIds = array_column($workplaces[0]['shifts'], 'id');
        $this->assertContains($this->workplaceShift->id, $shiftIds);
        $this->assertNotContains($inactiveShift->id, $shiftIds);
    }

    // --- POST /admin/forecast ---

    public function testGenerateRequiresAuthentication(): void
    {
        $this->postJson('/admin/forecast', [])->assertUnauthorized();
    }

    public function testGenerateRequiresShiftListPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/admin/forecast', $this->validPayload())->assertForbidden();
    }

    public function testGenerateValidationFailsWhenRequiredFieldsMissing(): void
    {
        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', []);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['data' => ['workplace_id', 'workplace_shift_id', 'date', 'requested_count']]);
    }

    public function testGenerateValidationFailsForPastDate(): void
    {
        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', [
            ...$this->validPayload(),
            'date' => '2026-06-25',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['data' => ['date']]);
    }

    public function testGenerateValidationFailsForZeroRequestedCount(): void
    {
        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', [
            ...$this->validPayload(),
            'requested_count' => 0,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['data' => ['requested_count']]);
    }

    public function testGenerateValidationFailsForNonExistentWorkplace(): void
    {
        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', [
            ...$this->validPayload(),
            'workplace_id' => 99999,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['data' => ['workplace_id']]);
    }

    public function testGenerateReturnsUnprocessableWhenShiftDoesNotBelongToWorkplace(): void
    {
        $otherWorkplace = Workplace::create([
            'name' => 'Other Warehouse',
            'address' => '4-4 Kobe',
            'latitude' => 34.6901,
            'longitude' => 135.1956,
        ]);
        $crossShift = WorkplaceShift::create([
            'workplace_id' => $otherWorkplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);

        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', [
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $crossShift->id,
            'date' => '2026-07-01',
            'requested_count' => 10,
        ]);

        $response->assertUnprocessable();
    }

    public function testGenerateReturnsExpectedTopLevelStructure(): void
    {
        $this->seedHistoricalShifts();

        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', $this->validPayload());

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('input', $data);
        $this->assertArrayHasKey('recommendation', $data);
        $this->assertArrayHasKey('demand_context', $data);
        $this->assertArrayHasKey('reliability', $data);
        $this->assertArrayHasKey('fill_speed', $data);
        $this->assertArrayHasKey('pool', $data);
        $this->assertArrayHasKey('candidates', $data);
        $this->assertArrayHasKey('warnings', $data);
    }

    public function testGenerateInputEchoesRequestValues(): void
    {
        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', $this->validPayload());

        $response->assertOk();
        $input = $response->json('data.input');

        $this->assertSame($this->workplace->id, $input['workplace_id']);
        $this->assertSame($this->workplaceShift->id, $input['workplace_shift_id']);
        $this->assertSame('2026-07-01', $input['date']);
        $this->assertSame(10, $input['requested_count']);
        $this->assertArrayHasKey('shift_start', $input);
        $this->assertArrayHasKey('shift_end', $input);
    }

    public function testGenerateWithRadiusKmParameter(): void
    {
        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', [
            ...$this->validPayload(),
            'radius_km' => 5.0,
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('pool', $response->json('data'));
    }

    public function testGenerateCandidatesUseShortNameForSriLankaNationality(): void
    {
        $this->seedHistoricalShifts();

        $member = User::factory()->create([
            'staff_no' => 'SL001',
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $member->info->update(['nationality' => Nationality::SriLanka->value]);
        WorkplaceUser::create(['user_id' => $member->id, 'workplace_id' => $this->workplace->id]);

        // Attend the two most recent historical shifts so the member has a track
        // record and qualifies as a callable candidate.
        Shift::query()
            ->where('workplace_id', $this->workplace->id)
            ->orderByDesc('date')
            ->limit(2)
            ->get()
            ->each(function (Shift $shift) use ($member) {
                $shiftUser = ShiftUser::create([
                    'shift_id' => $shift->id,
                    'user_id' => $member->id,
                    'status' => ShiftUserStatus::Approved->value,
                    'is_fixed' => false,
                    'is_overtime_allowed' => false,
                    'is_absent' => false,
                ]);
                CheckIn::create([
                    'shift_id' => $shift->id,
                    'shift_user_id' => $shiftUser->id,
                    'user_id' => $member->id,
                    'check_in_time' => "{$shift->date} 09:00:00",
                ]);
            });

        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', $this->validPayload());

        $response->assertOk();
        $candidate = collect($response->json('data.candidates'))->firstWhere('user_id', $member->id);
        $this->assertNotNull($candidate);
        $this->assertSame('THARINDU RAVISHANN', $candidate['name']);
    }

    public function testAbsentNoticeRateIgnoresRequestsTheAdminRefused(): void
    {
        // Two of the historical assignments filed a request; the admin refused one of them, so
        // that employee kept the shift and was told to work it. Only the request that still
        // stands belongs in the rate.
        $this->seedHistoricalShifts();
        $shiftUsers = ShiftUser::query()->orderBy('id')->limit(2)->get();
        ShiftAbsentNotice::create(['shift_user_id' => $shiftUsers[0]->id]);
        ShiftAbsentNotice::create([
            'shift_user_id' => $shiftUsers[1]->id,
            'status' => AbsentNoticeStatus::Rejected->value,
        ]);

        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', $this->validPayload());

        $response->assertOk();
        $reliability = $response->json('data.reliability');
        $approved = $reliability['approved_count'];
        $this->assertGreaterThan(0, $approved);
        $this->assertSame(round((1 / $approved) * 100, 1), $reliability['absent_notice_rate']);
    }

    public function testAbsentNoticeRateIsZeroWhenEveryRequestWasRefused(): void
    {
        $this->seedHistoricalShifts();
        ShiftUser::query()->orderBy('id')->limit(3)->get()->each(
            fn(ShiftUser $shiftUser) => ShiftAbsentNotice::create([
                'shift_user_id' => $shiftUser->id,
                'status' => AbsentNoticeStatus::Rejected->value,
            ])
        );

        $response = $this->actingAs($this->globalAdmin, 'api')->postJson('/admin/forecast', $this->validPayload());

        $response->assertOk();
        $this->assertSame(0, $response->json('data.reliability.absent_notice_rate'));
    }

    private function validPayload(): array
    {
        return [
            'workplace_id' => $this->workplace->id,
            'workplace_shift_id' => $this->workplaceShift->id,
            'date' => '2026-07-01',
            'requested_count' => 10,
        ];
    }

    private function seedHistoricalShifts(): void
    {
        $creator = User::factory()->create();

        for ($daysAgo = 7; $daysAgo <= 60; $daysAgo += 7) {
            $date = Carbon::now()->subDays($daysAgo)->toDateString();

            $shift = Shift::create([
                'workplace_id' => $this->workplace->id,
                'workplace_shift_id' => $this->workplaceShift->id,
                'creator_id' => $creator->id,
                'date' => $date,
                'adjusted_date' => $date,
                'fixed_total' => 0,
                'temp_total' => 10,
                'max_registered_temp' => 15,
                'break_minutes' => 60,
            ]);

            $workers = User::factory()->count(8)->create();
            foreach ($workers as $worker) {
                ShiftUser::create([
                    'shift_id' => $shift->id,
                    'user_id' => $worker->id,
                    'status' => ShiftUserStatus::Approved->value,
                    'is_fixed' => false,
                    'is_overtime_allowed' => false,
                    'is_absent' => false,
                ]);
            }
        }
    }
}
