<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Services\Reconciliation\CanonicalRow;
use App\Services\Reconciliation\ReconciliationFieldCompare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReconciliationFieldCompareTest extends TestCase
{
    use RefreshDatabase;

    public function testMinuteEqualityIgnoresSeconds(): void
    {
        $this->assertTrue(ReconciliationFieldCompare::timesMatch('2026-08-25 20:19:00', '2026-08-25 20:19:45'));
    }

    public function testComparesAgainstRoundedPaidTimes(): void
    {
        $checkIn = $this->makeCheckIn([
            'check_in_time' => '2026-08-25 20:19:00',
            'check_out_time' => '2026-08-26 06:03:00',
            'check_in_time_rounded' => '2026-08-25 21:00:00',
            'check_out_time_rounded' => '2026-08-26 06:00:00',
            'break_minutes' => 60,
        ]);
        $paidExternal = new CanonicalRow(
            'Test',
            '2026-08-25',
            Carbon::parse('2026-08-25 21:00:00'),
            Carbon::parse('2026-08-26 06:00:00'),
            60,
            []
        );
        $punchExternal = new CanonicalRow(
            'Test',
            '2026-08-25',
            Carbon::parse('2026-08-25 20:19:00'),
            Carbon::parse('2026-08-26 06:03:00'),
            60,
            []
        );

        $this->assertSame(
            ['check_in' => false, 'check_out' => false, 'break' => false],
            ReconciliationFieldCompare::differs($paidExternal, $checkIn)
        );
        $this->assertSame(
            ['check_in' => true, 'check_out' => true, 'break' => false],
            ReconciliationFieldCompare::differs($punchExternal, $checkIn)
        );
    }

    public function testPaidTimesFallBackToRawWhenNotRounded(): void
    {
        $checkIn = $this->makeCheckIn([
            'check_in_time' => '2026-08-25 20:19:00',
            'check_out_time' => null,
            'check_in_time_rounded' => null,
            'check_out_time_rounded' => null,
        ]);

        $this->assertSame('2026-08-25 20:19:00', ReconciliationFieldCompare::paidCheckIn($checkIn));
        $this->assertNull(ReconciliationFieldCompare::paidCheckOut($checkIn));
    }

    public function testPaidSnapshotCarriesHourBreakdown(): void
    {
        $checkIn = $this->makeCheckIn([
            'check_in_time_rounded' => '2026-08-25 21:00:00',
            'check_out_time_rounded' => '2026-08-26 06:00:00',
            'work_minutes' => 480,
            'work_minutes_normal' => 60,
            'work_minutes_overtime' => 0,
            'work_minutes_night' => 420,
            'work_minutes_night_overtime' => 0,
        ]);

        $this->assertSame(
            [
                'check_in' => '2026-08-25 21:00:00',
                'check_out' => '2026-08-26 06:00:00',
                'work_minutes' => 480,
                'work_minutes_normal' => 60,
                'work_minutes_overtime' => 0,
                'work_minutes_night' => 420,
                'work_minutes_night_overtime' => 0,
            ],
            ReconciliationFieldCompare::paidSnapshot($checkIn)
        );
    }

    public function testSummaryDiffersListsOnlyFieldsAmazonReportedDifferently(): void
    {
        $dgf = [
            'work_minutes' => 480,
            'work_minutes_normal' => 60,
            'work_minutes_overtime' => 0,
            'work_minutes_night' => 420,
            'work_minutes_night_overtime' => 0,
        ];
        $amazon = [
            'work_minutes' => 480,
            'work_minutes_normal' => 75,
            'work_minutes_overtime' => null,
            'work_minutes_night' => 405,
            'work_minutes_night_overtime' => 0,
        ];

        $this->assertSame(
            ['work_minutes_normal', 'work_minutes_night'],
            ReconciliationFieldCompare::summaryDiffers($amazon, $dgf)
        );
        $this->assertSame([], ReconciliationFieldCompare::summaryDiffers(null, $dgf));
        $this->assertSame([], ReconciliationFieldCompare::summaryDiffers($amazon, null));
    }

    public function testTemplateBreakMatchesAmazonSixty(): void
    {
        $checkIn = $this->makeCheckIn(['break_minutes' => null], 60);
        $external = new CanonicalRow(
            'Test',
            '2026-08-25',
            Carbon::parse('2026-08-25 20:19:00'),
            Carbon::parse('2026-08-26 06:03:00'),
            60,
            []
        );

        $this->assertSame(60, ReconciliationFieldCompare::effectiveBreakMinutes($checkIn));
        $this->assertFalse(ReconciliationFieldCompare::differs($external, $checkIn)['break']);
    }

    public function testNullCheckoutDiffersFromAmazonOut(): void
    {
        $checkIn = $this->makeCheckIn([
            'check_out_time' => null,
            'break_minutes' => 60,
        ]);
        $external = new CanonicalRow(
            'Test',
            '2026-08-25',
            Carbon::parse('2026-08-25 20:19:00'),
            Carbon::parse('2026-08-26 06:03:00'),
            60,
            []
        );

        $this->assertTrue(ReconciliationFieldCompare::differs($external, $checkIn)['check_out']);
    }

    private function makeCheckIn(array $overrides, int $templateBreak = 0): CheckIn
    {
        $workplace = Workplace::create([
            'name' => 'WP',
            'address' => 'Addr',
            'latitude' => 35,
            'longitude' => 139,
        ]);
        $template = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '21:00:00',
            'end_time' => '06:00:00',
            'break_duration' => $templateBreak,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $creator = User::factory()->create();
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $template->id,
            'creator_id' => $creator->id,
            'date' => '2026-08-25',
            'adjusted_date' => '2026-08-25',
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);

        $checkIn = new CheckIn();
        $checkIn->forceFill(
            array_merge(
                [
                    'shift_id' => $shift->id,
                    'shift_user_id' => 1,
                    'user_id' => 1,
                    'check_in_time' => '2026-08-25 20:19:00',
                    'check_out_time' => '2026-08-26 06:03:00',
                ],
                $overrides
            )
        );
        $checkIn->setRelation('shift', $shift->load('workplaceShift'));

        return $checkIn;
    }
}
