<?php

namespace Tests\Unit\Actions\Admin\FixedSchedule;

use App\Actions\Admin\FixedSchedule\GetFixedScheduleChangeRequestsAction;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\FixedScheduleChangeRequestDay;
use App\Models\FixedScheduleDetail;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class GetFixedScheduleChangeRequestsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private GetFixedScheduleChangeRequestsAction $action;

    private User $admin;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private WorkplaceShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(GetFixedScheduleChangeRequestsAction::class);

        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
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

        $this->admin = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->admin,
            PermissionName::FixedScheduleChangeRequestList,
            $this->workplace->id
        );
    }

    public function testFiltersByWorkplaceScopedToManagedWorkplaces(): void
    {
        $inScope = $this->createRequest($this->workplace);
        $outOfScope = $this->createRequest($this->otherWorkplace);

        $result = ($this->action)($this->admin, []);

        $ids = $result->getData()->pluck('id');
        $this->assertTrue($ids->contains($inScope->id));
        $this->assertFalse($ids->contains($outOfScope->id));
    }

    public function testFiltersByEmployeeNameSearch(): void
    {
        $target = User::factory()->create(['first_name' => 'TARO', 'last_name' => 'YAMADA']);
        $other = User::factory()->create(['first_name' => 'HANAKO', 'last_name' => 'SUZUKI']);
        $this->createRequest($this->workplace, employee: $target);
        $this->createRequest($this->workplace, employee: $other);

        $result = ($this->action)($this->admin, ['employee_name' => 'YAMADA']);

        $this->assertCount(1, $result->getData());
    }

    public function testFiltersByEmployeeNameSearchMatchesReversedFallbackShortName(): void
    {
        $target = User::factory()->create(['first_name' => 'Deshan', 'last_name' => 'Lokuruge']);
        $target->info->update(['nationality' => Nationality::SriLanka->value]);
        $other = User::factory()->create(['first_name' => 'Taro', 'last_name' => 'Sato']);
        $this->createRequest($this->workplace, employee: $target);
        $this->createRequest($this->workplace, employee: $other);

        // Raw first_name/last_name is "Lokuruge"/"Deshan"; searching what's displayed
        // ("DESHAN LOKURUGE", the reversed-fallback short_name) must also match.
        $result = ($this->action)($this->admin, ['employee_name' => 'DESHAN LOKURUGE']);

        $this->assertCount(1, $result->getData());
        $this->assertSame($target->id, $result->getData()->first()->user['id']);
    }

    public function testFiltersByStatus(): void
    {
        $this->createRequest($this->workplace, status: FixedScheduleChangeRequestStatus::Approved->value);
        $this->createRequest($this->workplace, status: FixedScheduleChangeRequestStatus::Pending->value);

        $result = ($this->action)($this->admin, ['status' => FixedScheduleChangeRequestStatus::Approved->value]);

        $this->assertCount(1, $result->getData());
        $this->assertSame(FixedScheduleChangeRequestStatus::Approved->value, $result->getData()->first()->status);
    }

    public function testDefaultsToPendingStatus(): void
    {
        $this->createRequest($this->workplace, status: FixedScheduleChangeRequestStatus::Approved->value);
        $this->createRequest($this->workplace, status: FixedScheduleChangeRequestStatus::Pending->value);

        $result = ($this->action)($this->admin, []);

        $this->assertCount(1, $result->getData());
        $this->assertSame(FixedScheduleChangeRequestStatus::Pending->value, $result->getData()->first()->status);
    }

    public function testFiltersByCreatedAtDateRangeWhenProvided(): void
    {
        $this->travelTo('2026-01-05');
        $januaryRequest = $this->createRequest($this->workplace);
        $this->travelTo('2026-02-10');
        $februaryRequest = $this->createRequest($this->workplace);

        $result = ($this->action)($this->admin, ['dates' => ['2026-02-01', '2026-02-28']]);

        $ids = $result->getData()->pluck('id');
        $this->assertTrue($ids->contains($februaryRequest->id));
        $this->assertFalse($ids->contains($januaryRequest->id));
    }

    public function testShowsShortenedNameForSriLankaEmployee(): void
    {
        $employee = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $employee->info->update(['nationality' => Nationality::SriLanka->value]);
        $this->createRequest($this->workplace, employee: $employee);

        $result = ($this->action)($this->admin, []);

        $this->assertSame('THARINDU RAVISHANN', $result->getData()->first()->user['name']);
    }

    public function testReturnsAllDatesWhenRangeNotProvided(): void
    {
        $this->travelTo('2026-01-05');
        $this->createRequest($this->workplace);
        $this->travelTo('2026-02-10');
        $this->createRequest($this->workplace);

        $result = ($this->action)($this->admin, []);

        $this->assertCount(2, $result->getData());
    }

    private function createRequest(
        Workplace $workplace,
        string $status = 'pending',
        ?User $employee = null
    ): FixedScheduleChangeRequest {
        $employee ??= User::factory()->create();
        $schedule = FixedSchedule::create(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        FixedScheduleDetail::create([
            'fixed_schedule_id' => $schedule->id,
            'day' => 1,
            'workplace_shift_id' => $this->shift->id,
        ]);
        $request = FixedScheduleChangeRequest::create([
            'fixed_schedule_id' => $schedule->id,
            'workplace_id' => $workplace->id,
            'status' => $status,
        ]);
        FixedScheduleChangeRequestDay::create([
            'fixed_schedule_change_request_id' => $request->id,
            'day' => 1,
            'current_workplace_shift_id' => $this->shift->id,
            'requested_workplace_shift_id' => null,
        ]);

        return $request;
    }
}
