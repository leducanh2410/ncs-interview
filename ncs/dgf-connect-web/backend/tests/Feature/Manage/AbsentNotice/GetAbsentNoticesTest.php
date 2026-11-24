<?php

namespace Tests\Feature\Manage\AbsentNotice;

use App\Enums\AbsentNoticeStatus;
use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftAbsentNotice;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetAbsentNoticesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/manage/absent-notices';

    private User $manager;

    private Permission $shiftListPermission;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-26 08:00:00');

        $this->shiftListPermission = Permission::create([
            'name' => PermissionName::ShiftList->value,
            'display_name' => 'Shift List',
        ]);

        $this->manager = User::factory()->create();
        $this->manager->givePermission($this->shiftListPermission, null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function testIndexRequiresShiftListPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->getJson(self::URL)->assertForbidden();
    }

    public function testIndexReturnsPaginatedResponseShape(): void
    {
        $this->createNotice(shiftDate: '2026-06-26');

        $response = $this->actingAs($this->manager, 'api')->getJson(self::URL);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'shift_id',
                        'shift_date',
                        'workplace_name',
                        'shift_time',
                        'user_name',
                        'position_name',
                        'requested_at',
                        'status',
                        'processed_by_name',
                        'processed_at',
                    ],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);
    }

    public function testIndexDefaultDateRangeIsTodayToSevenDaysAhead(): void
    {
        $this->createNotice(shiftDate: '2026-06-26');
        $this->createNotice(shiftDate: '2026-07-03');
        $this->createNotice(shiftDate: '2026-07-04');

        $response = $this->actingAs($this->manager, 'api')->getJson(self::URL);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total'));

        $dates = array_column($response->json('data.data'), 'shift_date');
        $this->assertContains('2026-06-26', $dates);
        $this->assertContains('2026-07-03', $dates);
        $this->assertNotContains('2026-07-04', $dates);
    }

    public function testIndexFiltersResultsByDateRange(): void
    {
        $this->createNotice(shiftDate: '2026-06-28');
        $this->createNotice(shiftDate: '2026-07-05');
        $this->createNotice(shiftDate: '2026-07-15');

        $response = $this->actingAs($this->manager, 'api')->getJson(
            self::URL .
                '?' .
                http_build_query([
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-10',
                ])
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('2026-07-05', $response->json('data.data.0.shift_date'));
    }

    public function testIndexFiltersResultsByStatus(): void
    {
        ['notice' => $pendingNotice] = $this->createNotice(shiftDate: '2026-06-27');
        ['notice' => $processedNotice] = $this->createNotice(shiftDate: '2026-06-28');
        $processedNotice->update(['status' => AbsentNoticeStatus::Processed->value]);

        $response = $this->actingAs($this->manager, 'api')->getJson(
            self::URL .
                '?' .
                http_build_query([
                    'date_from' => '2026-06-26',
                    'date_to' => '2026-07-03',
                    'status' => AbsentNoticeStatus::Processed->value,
                ])
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(AbsentNoticeStatus::Processed->value, $response->json('data.data.0.status'));
        $this->assertNotSame($pendingNotice->id, $response->json('data.data.0.id'));
    }

    public function testIndexFiltersResultsByWorkplaceId(): void
    {
        ['workplace' => $wpA] = $this->createNotice(shiftDate: '2026-06-27', workplaceName: 'Site A');
        $this->createNotice(shiftDate: '2026-06-27', workplaceName: 'Site B');

        $response = $this->actingAs($this->manager, 'api')->getJson(
            self::URL .
                '?' .
                http_build_query([
                    'date_from' => '2026-06-26',
                    'date_to' => '2026-07-03',
                    'workplace_id' => $wpA->id,
                ])
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('Site A', $response->json('data.data.0.workplace_name'));
    }

    public function testIndexValidationRejectsInvalidDateFormat(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson(self::URL . '?date_from=26-06-2026');

        $response->assertUnprocessable();
    }

    public function testIndexValidationRejectsInvalidStatus(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson(self::URL . '?status=unknown');

        $response->assertUnprocessable();
    }

    public function testIndexValidationRejectsNonExistentWorkplaceId(): void
    {
        $response = $this->actingAs($this->manager, 'api')->getJson(self::URL . '?workplace_id=99999');

        $response->assertUnprocessable();
    }

    public function testIndexResultsOrderedByShiftDateThenRequestedAt(): void
    {
        $noticeA = $this->createNotice(shiftDate: '2026-06-27', createdAt: '2026-06-26 09:00:00');
        $noticeB = $this->createNotice(shiftDate: '2026-06-27', createdAt: '2026-06-26 08:00:00');
        $noticeC = $this->createNotice(shiftDate: '2026-06-28', createdAt: '2026-06-26 08:00:00');

        $response = $this->actingAs($this->manager, 'api')->getJson(
            self::URL .
                '?' .
                http_build_query([
                    'date_from' => '2026-06-26',
                    'date_to' => '2026-07-03',
                ])
        );

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(3, $items);
        $this->assertSame($noticeB['notice']->id, $items[0]['id']);
        $this->assertSame($noticeA['notice']->id, $items[1]['id']);
        $this->assertSame($noticeC['notice']->id, $items[2]['id']);
    }

    private function createNotice(
        string $shiftDate,
        string $workplaceName = 'Test Workplace',
        string $createdAt = '2026-06-26 08:00:00'
    ): array {
        $user = User::factory()->create();

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
            'check_in_round_minutes' => 0,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 0,
        ]);

        $workplacePosition = WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
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
