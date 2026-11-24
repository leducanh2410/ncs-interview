<?php

namespace Tests\Concerns;

use App\Enums\Encoding;
use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\CheckIn;
use App\Models\Permission;
use App\Models\Salary;
use App\Models\SalaryRequest;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Fixtures for the employee-facing salary API.
 *
 * A salary row is normally written by SalaryService from check-ins, but a *finalized* one is
 * deliberately locked against recalculation (see SalaryResetScopeTest, which proves the
 * import flows leave exactly this shape behind and that a later recalculation must not touch
 * it). Building the amounts directly is therefore the same state the real flow produces,
 * without dragging a full check-in lifecycle into every salary assertion.
 */
trait BuildsSalaryFixtures
{
    protected function createEmployee(): User
    {
        return User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
    }

    /**
     * A settled month: its whole payable amount counts towards `min`, and a recalculation
     * triggered by the salary screen leaves it alone.
     */
    protected function createFinalizedSalary(
        User $user,
        string $month,
        int $totalAmount,
        array $attributes = []
    ): Salary {
        return $this->createSalary(
            $user,
            $month,
            $totalAmount,
            $attributes + ['is_finalized' => true, 'is_completed' => true]
        );
    }

    /**
     * A month still in progress: only 80% of it is payable, and it lands in `unfinalized`.
     * Callers must pass the *current* month - a fully elapsed unfinalized month with nothing
     * committed is zeroed by the recalculation the salary screen kicks off.
     */
    protected function createUnfinalizedSalary(
        User $user,
        string $month,
        int $totalAmount,
        array $attributes = []
    ): Salary {
        return $this->createSalary($user, $month, $totalAmount, $attributes + ['is_finalized' => false]);
    }

    /**
     * Real shifts and check-ins for a month, so SalaryService works the salary out itself.
     *
     * An unfinalized month cannot be built with createUnfinalizedSalary once it has elapsed:
     * the recalculation every salary flow kicks off zeroes a row with no check-ins behind it,
     * and it only survives by tripping the "lost all work time but has money committed"
     * warning branch - a state the system treats as needing human review, not a normal one.
     */
    protected function recordShiftsWorked(
        User $employee,
        Workplace $workplace,
        string $month,
        int $count,
        int $minutesEach,
        int $hourlyRate
    ): void {
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::create(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $creator = User::factory()->create();

        for ($day = 1; $day <= $count; $day++) {
            $date = sprintf('%s-%02d', $month, $day);
            $shift = Shift::create([
                'workplace_id' => $workplace->id,
                'workplace_shift_id' => $workplaceShift->id,
                'creator_id' => $creator->id,
                'date' => $date,
                'adjusted_date' => $date,
                'fixed_total' => 1,
                'temp_total' => 1,
            ]);
            $shiftUser = ShiftUser::create([
                'shift_id' => $shift->id,
                'user_id' => $employee->id,
                'workplace_position_id' => $position->id,
                'status' => ShiftUserStatus::Approved->value,
                'salary_per_hour' => $hourlyRate,
            ]);
            CheckIn::create([
                'shift_id' => $shift->id,
                'shift_user_id' => $shiftUser->id,
                'user_id' => $employee->id,
                'check_in_time' => "{$date} 09:00:00",
                'check_out_time' => "{$date} 18:00:00",
                'check_in_time_rounded' => "{$date} 09:00:00",
                'check_out_time_rounded' => "{$date} 18:00:00",
                'break_minutes' => 60,
                'work_minutes' => $minutesEach,
                'work_minutes_normal' => $minutesEach,
                'work_minutes_night' => 0,
                'work_minutes_overtime' => 0,
                'work_minutes_night_overtime' => 0,
            ]);
        }
    }

    /**
     * An admin holding exactly the salary permissions named, and nothing else, so a test can
     * show which permission each endpoint really gates on.
     */
    protected function createSalaryAdmin(PermissionName ...$permissions): User
    {
        $admin = User::factory()->create();
        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName->value],
                ['display_name' => $permissionName->value]
            );
            $admin->givePermission($permission, null);
        }

        return $admin;
    }

    /**
     * An admin able to walk a request through approve -> accountant approve -> paid, which is
     * the only way an employee's request reaches the states cancel/confirm-received react to.
     */
    protected function createSalaryApprover(): User
    {
        return $this->createSalaryAdmin(
            PermissionName::SalaryRequestApprove,
            PermissionName::SalaryRequestApproveAccountant,
            PermissionName::SalaryRequestConfirmPaid,
            PermissionName::SalaryRequestReject
        );
    }

    /**
     * Walk a request to the given status through the real admin endpoints, so the state the
     * employee-side assertions start from is one the system can actually produce.
     */
    protected function advanceRequestTo(SalaryRequest $salaryRequest, SalaryRequestStatus $status, User $approver): void
    {
        $base = "/manage/salary/requests/{$salaryRequest->id}";
        $owner = $salaryRequest->user;

        if ($status === SalaryRequestStatus::WaitForApproval) {
            // Where every request already starts.
            return;
        }

        if ($status === SalaryRequestStatus::Rejected) {
            $this->actingAs($approver, 'api')
                ->postJson("{$base}/reject", ['reason' => 'Not this month'])
                ->assertOk();
            $salaryRequest->refresh();

            return;
        }

        if ($status === SalaryRequestStatus::Cancelled) {
            $this->actingAs($owner, 'api')
                ->postJson("/user/salary/requests/{$salaryRequest->id}/cancel")
                ->assertOk();
            $salaryRequest->refresh();

            return;
        }

        // Everything past payment starts by getting the money out the door, then either the
        // employee says it arrived or the bank returns the file saying so.
        if (
            in_array(
                $status,
                [SalaryRequestStatus::Received, SalaryRequestStatus::ReceivedConfirmed, SalaryRequestStatus::Returned],
                true
            )
        ) {
            $this->advanceRequestTo($salaryRequest, SalaryRequestStatus::Paid, $approver);

            if ($status === SalaryRequestStatus::Received) {
                $this->actingAs($owner, 'api')
                    ->postJson("/user/salary/requests/{$salaryRequest->id}/confirm-received")
                    ->assertOk();
            } else {
                $this->importBankReturnFor($salaryRequest, $status);
            }

            $salaryRequest->refresh();

            return;
        }

        $steps = [
            SalaryRequestStatus::WaitForApprovalAccountant->value => ["{$base}/approve"],
            SalaryRequestStatus::WaitForPayment->value => ["{$base}/approve", "{$base}/approve"],
            SalaryRequestStatus::Paid->value => ["{$base}/approve", "{$base}/approve", "{$base}/confirm-paid"],
        ];

        foreach ($steps[$status->value] as $url) {
            $this->actingAs($approver, 'api')->postJson($url)->assertOk();
        }

        $salaryRequest->refresh();
    }

    /**
     * ReceivedConfirmed and Returned are only ever written by the bank return file, so they
     * are reached by feeding that file through the real import endpoint.
     */
    private function importBankReturnFor(SalaryRequest $salaryRequest, SalaryRequestStatus $status): void
    {
        $employee = $salaryRequest->user;
        if (!$employee->staff_no) {
            $employee->update(['staff_no' => 'T' . $employee->id]);
        }

        $header = array_fill(0, 17, '');
        $header[6] = 'お客様番号';
        $header[7] = '送金額';
        $header[13] = '送金完了更新日時';
        $header[16] = '期限切れ更新日時';

        $row = array_fill(0, 17, '');
        $row[6] = $employee->staff_no;
        $row[7] = $salaryRequest->amount;
        // Column 13 is "collected", column 16 is "expired"; exactly one may be filled in.
        $row[$status === SalaryRequestStatus::ReceivedConfirmed ? 13 : 16] = Carbon::parse($salaryRequest->paid_at)
            ->addDay()
            ->format('Y-m-d H:i:s');

        $this->actingAs($this->createSalaryAdmin(PermissionName::SalaryImportTransfer), 'api')
            ->postJson('/manage/salary/import-transfer', [
                'file' => UploadedFile::fake()->createWithContent(
                    'transfer.csv',
                    implode("\n", [implode(',', $header), implode(',', $row)]) . "\n"
                ),
                'encoding' => Encoding::UTF8->value,
            ])
            ->assertOk();
    }

    private function createSalary(User $user, string $month, int $totalAmount, array $attributes): Salary
    {
        // total_remaining is what the month still owes after everything already committed to
        // it. Deriving it keeps a row with an overridden total_paid/deductions/fee consistent
        // with what SalaryService would have written, instead of a state the system cannot
        // actually reach.
        $committed =
            ($attributes['total_paid'] ?? 0) + ($attributes['total_deductions'] ?? 0) + ($attributes['total_fee'] ?? 0);

        return Salary::query()->create(
            $attributes + [
                'user_id' => $user->id,
                'month' => $month,
                'total_amount' => $totalAmount,
                'total_remaining' => max(0, $totalAmount - $committed),
                'total_paid' => 0,
                'total_deductions' => 0,
                'total_fee' => 0,
                'min_salary' => 1000,
                'max_salary' => 1000,
                'total_minutes' => 3000,
                'is_completed' => false,
                'is_finalized' => false,
                'is_fully_paid' => false,
            ]
        );
    }
}
