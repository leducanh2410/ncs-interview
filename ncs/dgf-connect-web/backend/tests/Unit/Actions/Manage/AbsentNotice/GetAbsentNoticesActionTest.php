<?php

namespace Tests\Unit\Actions\Manage\AbsentNotice;

use App\Actions\Manage\AbsentNotice\GetAbsentNoticesAction;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetAbsentNoticesActionTest extends TestCase
{
    use RefreshDatabase;

    public function testReturnsPaginatedResultsWithCorrectFields()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $fixture = $this->createAbsentNoticeFixture(
            shiftDate: '2026-03-10',
            workplaceName: 'Warehouse A',
            positionName: 'Loader',
            userFirstName: 'Taro',
            userLastName: 'Yamada',
            createdAt: '2026-03-09 08:30:00'
        );

        $result = app(GetAbsentNoticesAction::class)([]);

        $this->assertNull($result->getError());
        $this->assertInstanceOf(LengthAwarePaginator::class, $result->getData());
        $rows = $result->getData()->items();
        $this->assertCount(1, $rows);
        $this->assertSame($fixture['notice']->id, $rows[0]['id']);
        $this->assertSame($fixture['shift']->id, $rows[0]['shift_id']);
        $this->assertSame('2026-03-10', $rows[0]['shift_date']);
        $this->assertSame('Warehouse A', $rows[0]['workplace_name']);
        $this->assertSame('09:00 - 18:00', $rows[0]['shift_time']);
        $this->assertSame('YAMADA TARO', $rows[0]['user_name']);
        $this->assertSame('Loader', $rows[0]['position_name']);
        $this->assertNotEmpty($rows[0]['requested_at']);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertNull($rows[0]['processed_by_name']);
        $this->assertNull($rows[0]['processed_at']);
    }

    public function testFiltersByStatus()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $pending = $this->createAbsentNoticeFixture(shiftDate: '2026-03-10', createdAt: '2026-03-09 08:00:00');
        $processed = $this->createAbsentNoticeFixture(shiftDate: '2026-03-10', createdAt: '2026-03-09 08:10:00');
        $processed['notice']->update(['status' => 'processed']);

        $result = app(GetAbsentNoticesAction::class)([
            'date_from' => '2026-03-09',
            'date_to' => '2026-03-16',
            'status' => 'processed',
        ]);
        $rows = $result->getData()->items();

        $this->assertCount(1, $rows);
        $this->assertSame($processed['notice']->id, $rows[0]['id']);
    }

    public function testDateRangeDefaultsToTodayThroughSevenDaysAhead()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->createAbsentNoticeFixture(shiftDate: '2026-03-09', createdAt: '2026-03-09 08:00:00');
        $this->createAbsentNoticeFixture(shiftDate: '2026-03-16', createdAt: '2026-03-09 08:10:00');
        $this->createAbsentNoticeFixture(shiftDate: '2026-03-17', createdAt: '2026-03-09 08:20:00');

        $result = app(GetAbsentNoticesAction::class)([]);
        $rows = $result->getData()->items();

        $this->assertCount(2, $rows);
        $this->assertSame(['2026-03-09', '2026-03-16'], array_column($rows, 'shift_date'));
    }

    public function testWorkplaceIdFilter()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $fixtureA = $this->createAbsentNoticeFixture(
            shiftDate: '2026-03-10',
            workplaceName: 'Warehouse A',
            createdAt: '2026-03-09 08:00:00'
        );
        $this->createAbsentNoticeFixture(
            shiftDate: '2026-03-10',
            workplaceName: 'Warehouse B',
            createdAt: '2026-03-09 08:10:00'
        );

        $result = app(GetAbsentNoticesAction::class)([
            'date_from' => '2026-03-09',
            'date_to' => '2026-03-16',
            'workplace_id' => $fixtureA['workplace']->id,
        ]);
        $rows = $result->getData()->items();

        $this->assertCount(1, $rows);
        $this->assertSame('Warehouse A', $rows[0]['workplace_name']);
    }

    public function testSortsByShiftDateAscendingThenRequestedAtAscending()
    {
        $this->travelTo(now()->setDate(2026, 3, 9)->setTime(8, 0));
        $this->createAbsentNoticeFixture(shiftDate: '2026-03-11', createdAt: '2026-03-09 08:30:00');
        $this->createAbsentNoticeFixture(shiftDate: '2026-03-10', createdAt: '2026-03-09 09:00:00');
        $this->createAbsentNoticeFixture(shiftDate: '2026-03-10', createdAt: '2026-03-09 08:00:00');

        $result = app(GetAbsentNoticesAction::class)([
            'date_from' => '2026-03-09',
            'date_to' => '2026-03-16',
        ]);
        $rows = $result->getData()->items();

        $this->assertSame(['2026-03-10', '2026-03-10', '2026-03-11'], array_column($rows, 'shift_date'));
        $this->assertLessThan($rows[1]['requested_at'], $rows[0]['requested_at']);
    }

    private function createAbsentNoticeFixture(
        string $shiftDate,
        string $workplaceName = 'Test Workplace',
        string $positionName = 'Staff',
        string $userFirstName = 'John',
        string $userLastName = 'Doe',
        string $createdAt = '2026-03-09 08:00:00'
    ): array {
        $user = User::factory()->create([
            'first_name' => $userFirstName,
            'last_name' => $userLastName,
            'first_name_kana' => $userFirstName,
            'last_name_kana' => $userLastName,
        ]);

        $workplace = Workplace::create([
            'name' => $workplaceName,
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

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => $positionName,
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $shiftDate,
            'adjusted_date' => $shiftDate,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'workplace_position_id' => $workplacePosition->id,
            'status' => 'approved',
        ]);

        $notice = ShiftAbsentNotice::create(['shift_user_id' => $shiftUser->id]);
        $notice
            ->forceFill([
                'created_at' => Carbon::parse($createdAt),
                'updated_at' => Carbon::parse($createdAt),
            ])
            ->save();

        return [
            'notice' => $notice,
            'workplace' => $workplace,
            'shift' => $shift,
        ];
    }
}
