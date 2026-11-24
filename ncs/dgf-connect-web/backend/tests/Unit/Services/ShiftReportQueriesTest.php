<?php

namespace Tests\Unit\Services;

use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftReport;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Services\Manage\ShiftReportService;
use App\Services\User\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftReportQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create([
            'name' => RoleName::GlobalAdmin->value,
            'display_name' => 'Global Admin',
        ]);
    }

    public function testUserCheckInReportsUsesShiftReportAbsentFlagWhenShiftUsersAlsoHasIsAbsent(): void
    {
        $user = User::factory()->create();
        $context = $this->createShiftContext($user, '2026-03-10', false);

        ShiftReport::create([
            'shift_user_id' => $context['shiftUser']->id,
            'user_id' => $user->id,
            'date' => '2026-03-10',
            'late_minutes' => null,
            'early_minutes' => null,
            'is_absent' => true,
        ]);

        $otherUser = User::factory()->create();
        $otherContext = $this->createShiftContext($otherUser, '2026-03-11', true);

        ShiftReport::create([
            'shift_user_id' => $otherContext['shiftUser']->id,
            'user_id' => $otherUser->id,
            'date' => '2026-03-11',
            'late_minutes' => 5,
            'early_minutes' => null,
            'is_absent' => false,
        ]);

        $this->actingAs($user, 'api');

        $reports = app(CheckInService::class)->getReports(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertCount(1, $reports);
        $this->assertTrue((bool) $reports->first()->is_absent);
        $this->assertSame($context['workplace']->name, $reports->first()->workplace_name);
    }

    public function testMonthlyShiftReportAggregatesUseShiftReportAbsentFlagWhenShiftUsersAlsoHasIsAbsent(): void
    {
        $manager = User::factory()->create();
        $manager->addRole(Role::where('name', RoleName::GlobalAdmin->value)->first());

        $user = User::factory()->create();
        $context = $this->createShiftContext($user, '2026-03-10', false);

        ShiftReport::create([
            'shift_user_id' => $context['shiftUser']->id,
            'user_id' => $user->id,
            'date' => '2026-03-10',
            'late_minutes' => 15,
            'early_minutes' => 10,
            'is_absent' => true,
        ]);

        $result = app(ShiftReportService::class)->listMonthly(
            [
                'month' => '2026-03',
                'page_size' => 15,
                'user_id' => $user->id,
            ],
            $manager
        );

        $this->assertSame(1, $result->total());

        $item = $result->items()[0];

        $this->assertSame($user->id, $item->id);
        $this->assertSame(1, (int) $item->late_count);
        $this->assertSame(15, (int) $item->late_minutes);
        $this->assertSame(1, (int) $item->early_count);
        $this->assertSame(10, (int) $item->early_minutes);
        $this->assertSame(1, (int) $item->absent_count);
    }

    /**
     * @return array{workplace: Workplace, shiftUser: ShiftUser}
     */
    private function createShiftContext(User $user, string $date, bool $isAbsent): array
    {
        $workplace = Workplace::create([
            'name' => 'Workplace ' . $user->id . ' ' . $date,
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
            'is_absent' => $isAbsent,
        ]);

        return [
            'workplace' => $workplace,
            'shiftUser' => $shiftUser,
        ];
    }
}
