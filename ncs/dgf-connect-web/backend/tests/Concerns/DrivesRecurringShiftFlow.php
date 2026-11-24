<?php

namespace Tests\Concerns;

use App\Enums\FaceRecognitionProviderName;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

trait DrivesRecurringShiftFlow
{
    protected User $shiftManager;

    /** @var array<int, WorkplaceShift> */
    private array $flowWorkplaceShifts = [];

    /** @var array<int, WorkplacePosition> */
    private array $flowWorkplacePositions = [];

    protected function setUpRecurringShiftFlow(): void
    {
        config([
            'services.face_recognition.provider' => FaceRecognitionProviderName::Rekognition->value,
            'work.max_hour_per_week' => 168,
        ]);

        $this->shiftManager = User::factory()->create();
        foreach ([PermissionName::ShiftEdit, PermissionName::ShiftCheckIn, PermissionName::WorkHistoryAdd] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name->value], ['display_name' => $name->value]);
            $this->shiftManager->givePermission($permission, null);
        }
    }

    protected function travelToDate(string $dateTime): void
    {
        Carbon::setTestNow($dateTime);
    }

    protected function createRecurringMember(Workplace ...$workplaces): User
    {
        $employee = User::factory()->create([
            'staff_no' => 'EMP' . fake()->unique()->numerify('#####'),
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $employee->addRole($role);
        $employee->info()->update([
            'residence_expiry_date_1' => '2035-12-31',
            'passport_expiry_date' => '2035-12-31',
            'student_card_expiry_date' => null,
        ]);

        foreach ($workplaces as $workplace) {
            WorkplaceUser::create(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        }

        return $employee;
    }

    protected function approveShift(User $employee, Workplace $workplace, string $date): ShiftUser
    {
        $shift = $this->createFlowShift($workplace, $date);
        $position = $this->flowWorkplacePositions[$workplace->id];

        $response = $this->actingAs($this->shiftManager, 'api')->putJson(
            "/manage/shifts/{$shift->id}",
            $this->shiftPayload(approved: [$position->id => [$employee->id]])
        );
        $this->assertSame(200, $response->status(), $response->getContent());

        return ShiftUser::query()->where('shift_id', $shift->id)->where('user_id', $employee->id)->firstOrFail();
    }

    protected function rejectShift(ShiftUser $shiftUser): void
    {
        $this->actingAs($this->shiftManager, 'api')
            ->putJson("/manage/shifts/{$shiftUser->shift_id}", $this->shiftPayload(rejected: [$shiftUser->user_id]))
            ->assertOk();
    }

    protected function workShift(ShiftUser $shiftUser): void
    {
        $date = Shift::query()->whereKey($shiftUser->shift_id)->value('date');
        $date = CarbonImmutable::parse($date)->toDateString();

        $this->actingAs($this->shiftManager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-in", ['date' => $date, 'time' => '09:00'])
            ->assertOk();
        $this->actingAs($this->shiftManager, 'api')
            ->postJson("/manage/shifts/shift-users/{$shiftUser->id}/check-out", ['date' => $date, 'time' => '18:00'])
            ->assertOk();
    }

    /**
     * @return list<ShiftUser>
     */
    protected function approveAndWorkDays(User $employee, Workplace $workplace, string $firstDate, int $count): array
    {
        $shiftUsers = [];
        $date = CarbonImmutable::parse($firstDate);
        for ($i = 0; $i < $count; $i++) {
            $this->travelToDate($date->toDateString() . ' 08:00:00');
            $shiftUser = $this->approveShift($employee, $workplace, $date->toDateString());
            $this->travelToDate($date->toDateString() . ' 19:00:00');
            $this->workShift($shiftUser);
            $shiftUsers[] = $shiftUser;
            $date = $date->addDay();
        }

        return $shiftUsers;
    }

    protected function addWorkedShiftThroughWorkHistory(User $employee, Workplace $workplace, string $date): ShiftUser
    {
        $shift = $this->createFlowShift($workplace, $date);

        $this->actingAs($this->shiftManager, 'api')
            ->postJson('/manage/work-histories/add', ['shift_id' => $shift->id, 'user_ids' => [$employee->id]])
            ->assertOk();

        return ShiftUser::query()->where('shift_id', $shift->id)->where('user_id', $employee->id)->firstOrFail();
    }

    protected function authCheck(User $employee): TestResponse
    {
        return $this->actingAs($employee, 'api')->getJson('/auth/check')->assertOk();
    }

    /**
     * @return list<int>
     */
    protected function pendingPollIds(User $employee): array
    {
        return collect($this->authCheck($employee)->json('data.pending_votes'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function pendingExamIds(User $employee): array
    {
        return collect($this->authCheck($employee)->json('data.pending_exams'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    private function createFlowShift(Workplace $workplace, string $date): Shift
    {
        $workplaceShift = $this->flowWorkplaceShifts[$workplace->id] ??= WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $this->flowWorkplacePositions[$workplace->id] ??= WorkplacePosition::create([
            'workplace_id' => $workplace->id,
            'name' => 'Staff',
        ]);

        return Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $this->shiftManager->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 5,
            'fixed_total' => 0,
        ]);
    }

    private function shiftPayload(array $approved = [], array $rejected = []): array
    {
        return [
            'fixed_total' => 0,
            'temp_total' => 5,
            'fixed' => ['pending_ids' => [], 'rejected_ids' => [], 'approved_ids' => []],
            'temp' => ['pending_ids' => [], 'rejected_ids' => $rejected, 'approved_ids' => $approved],
        ];
    }
}
