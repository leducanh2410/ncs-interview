<?php

namespace App\Jobs;

use App\Enums\SalaryProcessStatus;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Facades\SalaryFacade;
use App\Models\Salary;
use App\Models\SalaryProcess;
use App\Models\SalaryRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCreateSalaryRequest implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hasProcessing = SalaryProcess::query()->where('status', SalaryProcessStatus::Processing->value)->exists();
        if ($hasProcessing) {
            return;
        }

        $salaryProcesses = SalaryProcess::query()
            ->where('status', SalaryProcessStatus::Pending->value)
            ->where('scheduled_at', '<=', now())
            ->orderByDesc('priority')
            ->orderBy('scheduled_at')
            ->get();

        if ($salaryProcesses->isEmpty()) {
            return;
        }

        Log::info(
            "AutoCreateSalaryRequest: Start. Month = {$salaryProcesses->first()->month}, Workplace = {$salaryProcesses->first()->workplace_id}"
        );

        $salaryProcess = $salaryProcesses->first();

        $salaryProcess->update(['status' => SalaryProcessStatus::Processing->value]);

        $month = Carbon::parse($salaryProcess->month)->startOfMonth();

        $reports = DB::table('work_time_reports')
            ->join(
                'work_time_report_details',
                'work_time_report_details.work_time_report_id',
                '=',
                'work_time_reports.id'
            )
            ->where('work_time_reports.month', $salaryProcess->month)
            ->selectRaw('DISTINCT work_time_reports.user_id, work_time_report_details.workplace_id')
            ->get()
            ->mapToGroups(function ($item) {
                return [$item->user_id => $item->workplace_id];
            });

        $userIds = [];
        foreach ($reports as $userId => $workplaceIds) {
            if (!in_array($salaryProcess->workplace_id, $workplaceIds->toArray())) {
                continue;
            }

            // No deferring to a higher-priority workplace here: the queue is walked in
            // priority order, so the workplace that outranks the others reaches its employees
            // first, and every later run finds they already have an open request and skips
            // them. See AutoCreateSalaryRequestTest for both halves of that contract.
            $userIds[] = $userId;
            SalaryFacade::collectUsersSalary([$userId], $month, $month->clone()->endOfMonth());
        }

        $salaries = Salary::query()
            ->whereIn('user_id', $userIds)
            ->where('month', $salaryProcess->month)
            ->get()
            ->keyBy('user_id');

        $salaryRequests = SalaryRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', SalaryRequestStatus::getActiveStatuses())
            ->get()
            ->pluck('user_id');

        foreach ($salaries as $userId => $salary) {
            if ($salaryRequests->contains($userId)) {
                Log::info("AutoCreateSalaryRequest: Skip user {$userId}. Already has request");

                continue;
            }

            $totalSubtracted = $salary->total_deductions + $salary->total_paid + $salary->total_fee;
            $canReceiveAmount = $salary->is_finalized ? $salary->total_amount : floor($salary->total_amount * 0.8);
            $maxAmount = max(0, $canReceiveAmount - $totalSubtracted);
            $requestAmount = floor($maxAmount / 10000) * 10000;
            $requestAmount = min($requestAmount, config('salary.request.max_transfer_amount'));

            if ($requestAmount <= 0) {
                Log::info("AutoCreateSalaryRequest: Skip user {$userId}. Max amount = {$maxAmount}");

                continue;
            }

            // The fee is billed when the month can still absorb it *after* the advance. That
            // has to be measured against the month's real balance, not against $maxAmount:
            // for an unfinalized month $maxAmount is already capped at 80%, so measuring
            // there would skip the fee while a quarter of the month sat untouched. This is
            // the same sum ImportTransferInfoAction checks when it books the payment, so a
            // request marked here can always be booked.
            //
            // BUSINESS DECISION: when the advance drains the month exactly there is nothing
            // left to take the fee from and the company absorbs it, rather than rounding the
            // advance down a further 10000 - which would cost every employee whose payable
            // amount lands just above a 10000 step another 10000 yen, or, below 10330, their
            // advance entirely. It is why this job can raise a request that
            // SalaryService::calculateMonthlyAmount would refuse on the manual endpoints.
            $hasFee = $salary->total_amount - $totalSubtracted - $requestAmount >= (int) config('salary.transfer_fee');

            DB::transaction(function () use ($userId, $salary, $maxAmount, $requestAmount, $hasFee) {
                $salaryRequest = SalaryRequest::query()->create([
                    'user_id' => $userId,
                    'max_amount' => $maxAmount,
                    'amount' => $requestAmount,
                    'status' => SalaryRequestStatus::WaitForPayment,
                    'type' => SalaryRequestType::Transfer->value,
                    'approved_at' => now(),
                    'accountant_approved_at' => now(),
                ]);

                // The detail rows are what ImportTransferInfoAction books against the salary
                // once the bank confirms the payment, so they have to add up to the amount
                // actually being transferred - not to the maximum it was rounded down from.
                $salaryRequest->details()->create([
                    'salary_id' => $salary->id,
                    'amount' => $requestAmount,
                    'has_fee' => $hasFee,
                ]);
            });
        }

        $salaryProcess->update(['status' => SalaryProcessStatus::Completed->value]);

        Log::info(
            "AutoCreateSalaryRequest: End. Month = {$salaryProcess->month}, Workplace = {$salaryProcess->workplace_id}"
        );
    }
}
