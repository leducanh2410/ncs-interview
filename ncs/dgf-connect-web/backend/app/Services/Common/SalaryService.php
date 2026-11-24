<?php

namespace App\Services\Common;

use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Models\CheckIn;
use App\Models\Salary;
use App\Models\SystemConfig;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceShiftLeader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SalaryService
{
    public function collectUsersSalary(array $userIds, Carbon $fromDate, Carbon $toDate, bool $isReset = false): void
    {
        $fromMonth = $fromDate->format('Y-m');
        $toMonth = $toDate->format('Y-m');
        if ($isReset) {
            Salary::query()
                ->whereIn('user_id', $userIds)
                ->whereBetween('month', [$fromMonth, $toMonth])
                ->delete();
        }

        [$allData, $months] = $this->buildSalaryData($userIds, $fromDate, $toDate);

        $salaries = Salary::query()->whereIn('user_id', $userIds)->whereIn('month', $months)->get()->groupBy('user_id');
        DB::transaction(function () use ($allData, $salaries, $userIds, $fromDate, $toDate) {
            $this->resetSalariesWithoutWorkTime($userIds, $fromDate, $toDate, $allData);

            foreach ($allData as $userId => $data) {
                $userSalaries = $salaries[$userId] ?? null;
                if ($userSalaries) {
                    $userSalaries = $userSalaries->keyBy('month');
                }
                foreach ($data as $month => $salaryData) {
                    $currentSalary = $userSalaries[$month] ?? null;
                    $isCompleted = Carbon::parse("{$month}-01")
                        ->endOfMonth()
                        ->addDay()
                        ->lte(Carbon::now());
                    if ($currentSalary) {
                        if ($this->isSalaryLocked($currentSalary)) {
                            continue;
                        }

                        $currentSalary->update([
                            'total_amount' => $salaryData['total'],
                            'total_remaining' =>
                                $salaryData['total'] -
                                $currentSalary->total_paid -
                                $currentSalary->total_deductions -
                                $currentSalary->total_fee,
                            'min_salary' => $salaryData['min'],
                            'max_salary' => $salaryData['max'],
                            'total_minutes' => $salaryData['total_minutes'],
                            'is_completed' => $isCompleted,
                        ]);
                    } else {
                        Salary::query()->create([
                            'user_id' => $userId,
                            'month' => $month,
                            'total_amount' => $salaryData['total'],
                            'total_remaining' => $salaryData['total'],
                            'min_salary' => $salaryData['min'],
                            'max_salary' => $salaryData['max'],
                            'total_minutes' => $salaryData['total_minutes'],
                            'is_completed' => $isCompleted,
                        ]);
                    }
                }
            }
        });

        // The request limit shown to the employee is cached for an hour and is read directly
        // when validating a new request, so it has to go whenever the amounts move.
        foreach ($userIds as $userId) {
            Cache::forget(CollectUserSalaryAction::CACHE_KEY_PREFIX . $userId);
        }
    }

    /**
     * The amount a month would be worth if the salary were recalculated right now, based on
     * the check-ins as they currently stand. Read-only, so callers can check the outcome of a
     * pending change before committing to it.
     */
    public function previewMonthTotal(int $userId, string $month, ?int $ignoreShiftUserId = null): float
    {
        $from = Carbon::parse("{$month}-01")->startOfMonth();
        [$allData] = $this->buildSalaryData([$userId], $from, $from->copy()->endOfMonth(), $ignoreShiftUserId);

        return (float) ($allData[$userId][$month]['total'] ?? 0);
    }

    public function getMinMaxForRequest(Collection $salaries): array
    {
        $max = 0;
        $min = 0;
        $totalFinalizedAmount = 0;
        $totalUnfinalizedAmount = 0;
        $finalizedAmounts = [];
        $unfinalizedAmounts = [];
        foreach ($salaries as $salary) {
            $monthAmount = $salary->getPayableAmount();
            if ($salary->is_finalized) {
                $min += $monthAmount;
                $totalFinalizedAmount += $monthAmount;
                $finalizedAmounts[$salary->month] = $monthAmount;
            } else {
                $totalUnfinalizedAmount += $monthAmount;
                $unfinalizedAmounts[$salary->month] = $monthAmount;
            }

            $max += $monthAmount;
        }

        $minTransferAmount = $this->calculateMinTransfer($finalizedAmounts, $unfinalizedAmounts, $totalFinalizedAmount);

        return [
            'min' => $min,
            'max' => $max,
            'unfinalized' => $totalUnfinalizedAmount,
            'min_transfer' => $minTransferAmount,
            'max_transfer' => min(
                config('salary.request.max_transfer_amount'),
                $this->calculateMaxTransfer($finalizedAmounts, $unfinalizedAmounts, $minTransferAmount, $max)
            ),
        ];
    }

    public function calculateMinTransfer(array $finalizedAmounts, array $unfinalizedAmounts, int $totalFinalizedAmount)
    {
        $totalFinalizedAmountLower = (int) floor($totalFinalizedAmount / 1000) * 1000;
        $totalFinalizedAmountHigher = (int) ceil($totalFinalizedAmount / 1000) * 1000;

        if ($totalFinalizedAmountHigher === $totalFinalizedAmount) {
            $totalFinalizedAmountHigher += 1000;
        }

        $result = $this->calculateMonthlyAmount(
            $finalizedAmounts,
            $unfinalizedAmounts,
            $totalFinalizedAmountHigher,
            true
        );

        if ($result) {
            return $totalFinalizedAmountHigher;
        }

        if ($totalFinalizedAmountLower === $totalFinalizedAmount) {
            $totalFinalizedAmountLower -= 1000;
        }

        if ($totalFinalizedAmountLower > 0) {
            $result = $this->calculateMonthlyAmount(
                $finalizedAmounts,
                $unfinalizedAmounts,
                $totalFinalizedAmountLower,
                true
            );

            if ($result) {
                return $totalFinalizedAmountLower;
            }
        }

        $minAmount = $totalFinalizedAmountHigher + 1000;
        while ($minAmount < $totalFinalizedAmount) {
            $result = $this->calculateMonthlyAmount($finalizedAmounts, $unfinalizedAmounts, $minAmount, true);

            if ($result) {
                return $minAmount;
            }

            $minAmount += 1000;
        }

        return 0;
    }

    public function calculateMaxTransfer(
        array $finalizedAmounts,
        array $unfinalizedAmounts,
        int $minAmount,
        int $totalAmount
    ) {
        $maxAmountLower = (int) floor($totalAmount / 1000) * 1000;
        if ($maxAmountLower === $totalAmount) {
            $maxAmountLower -= 1000;
        }

        $maxTransferAmount = $maxAmountLower;
        while ($maxTransferAmount > $minAmount) {
            $result = $this->calculateMonthlyAmount($finalizedAmounts, $unfinalizedAmounts, $maxTransferAmount, true);

            if ($result) {
                return $maxTransferAmount;
            }

            $maxTransferAmount -= 1000;
        }

        return $minAmount;
    }

    public function calculateMonthlyAmount(
        array $finalizedAmounts,
        array $unfinalizedAmounts,
        int $totalAmount,
        bool $isTransfer
    ): ?array {
        $total = 0;
        $monthlyAmounts = [];
        $transferFee = config('salary.transfer_fee');
        foreach ($finalizedAmounts as $month => $amount) {
            if ($total + $amount < $totalAmount) {
                $total += $amount;
                $monthlyAmounts[] = [
                    'month' => $month,
                    'amount' => $amount,
                ];

                continue;
            }

            $remain = $total + $amount - $totalAmount;
            if ($isTransfer && $remain < $transferFee) {
                // Cannot pay transfer fee

                return null;
            }

            $monthlyAmounts[] = [
                'month' => $month,
                'amount' => $totalAmount - $total,
            ];

            if (count($monthlyAmounts) < count($finalizedAmounts)) {
                // Need to request all finalized months
                return null;
            }

            return [
                'amounts' => $monthlyAmounts,
                'fee_month' => $isTransfer ? $month : null,
            ];
        }

        foreach ($unfinalizedAmounts as $month => $amount) {
            if ($total + $amount < $totalAmount) {
                $total += $amount;
                $monthlyAmounts[] = [
                    'month' => $month,
                    'amount' => $amount,
                ];

                continue;
            }

            $remain = $total + $amount - $totalAmount;
            if ($isTransfer && $remain < $transferFee) {
                // Cannot pay transfer fee
                return null;
            }

            $monthlyAmounts[] = [
                'month' => $month,
                'amount' => $totalAmount - $total,
            ];

            return [
                'amounts' => $monthlyAmounts,
                'fee_month' => $isTransfer ? $month : null,
            ];
        }

        return null;
    }

    public function getUnpaidSalaries(int $userId): Collection
    {
        return Salary::query()
            ->where('user_id', $userId)
            ->where('is_fully_paid', false)
            ->orderByDesc('is_finalized')
            ->orderBy('month')
            ->get();
    }

    /**
     * Compute the salary totals per user per month from the current check-in data without
     * writing anything.
     *
     * @return array{0: array<int, array<string, array{total: string|int, min: int, max: int, total_minutes: float}>>, 1: string[]}
     */
    private function buildSalaryData(
        array $userIds,
        Carbon $fromDate,
        Carbon $toDate,
        ?int $ignoreShiftUserId = null
    ): array {
        $workHistories = CheckIn::query()
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->join('shift_users', 'shift_users.id', '=', 'check_ins.shift_user_id')
            ->select([
                'check_ins.user_id',
                'check_ins.work_minutes',
                'check_ins.work_minutes_normal',
                'check_ins.work_minutes_night',
                'check_ins.work_minutes_overtime',
                'check_ins.work_minutes_night_overtime',
                'shifts.date',
                'shifts.workplace_id',
                'shifts.workplace_shift_id',
                'shift_users.salary_per_hour as salary_per_hour',
                'shift_users.workplace_position_id',
            ])
            ->whereIn('check_ins.user_id', $userIds)
            ->shiftNotAbsent()
            ->when($ignoreShiftUserId, function (Builder $builder, int $ignoreShiftUserId) {
                $builder->where('check_ins.shift_user_id', '!=', $ignoreShiftUserId);
            })
            ->where('check_ins.work_minutes', '>', 0)
            ->where('shifts.date', '>=', $fromDate->format('Y-m-d'))
            ->where('shifts.date', '<=', $toDate->format('Y-m-d'))
            ->orderBy('shifts.date')
            ->get();

        $workplaceIds = $workHistories->pluck('workplace_id')->unique()->values()->toArray();
        $workplaceShiftIds = $workHistories->pluck('workplace_shift_id')->unique()->values()->toArray();
        $workplacePositionIds = $workHistories->pluck('workplace_position_id')->unique()->values()->toArray();

        $workplaceSalaries = Workplace::query()
            ->whereIn('id', $workplaceIds)
            ->whereNotNull('salary_per_hour')
            ->pluck('salary_per_hour', 'id');
        $workplaceShiftSalaries = WorkplaceShift::query()
            ->whereIn('id', $workplaceShiftIds)
            ->whereNotNull('salary_per_hour')
            ->pluck('salary_per_hour', 'id');
        $workplacePositionSalaries = WorkplacePosition::query()
            ->whereIn('id', $workplacePositionIds)
            ->whereNotNull('salary_per_hour')
            ->pluck('salary_per_hour', 'id');
        $workplaceShiftLeaders = WorkplaceShiftLeader::query()
            ->whereIn('workplace_shift_id', $workplaceShiftIds)
            ->get()
            ->groupBy('workplace_shift_id');

        $systemSalary = config('salary.salary_per_hour');
        $systemConfig = SystemConfig::query()->first();
        if ($systemConfig) {
            $systemSalary = $systemConfig->salary_per_hour;
        }

        $allData = [];
        $months = [];
        foreach ($workHistories->groupBy('user_id') as $userId => $userWorkHistories) {
            $data = [];
            foreach ($userWorkHistories as $workHistory) {
                $month = Str::substr($workHistory->date, 0, 7);
                if (!in_array($month, $months)) {
                    $months[] = $month;
                }
                $total = Arr::get($data, "{$month}.total", 0);
                $minSalary = Arr::get($data, "{$month}.min", -1);
                $maxSalary = Arr::get($data, "{$month}.max", -1);
                $totalMinutes = Arr::get($data, "{$month}.total_minutes", 0);
                $salary = $systemSalary;
                $hasSpecificSalary = false;
                if ($workHistory->salary_per_hour) {
                    $salary = $workHistory->salary_per_hour;
                    $hasSpecificSalary = true;
                } elseif ($workplacePositionSalaries->has($workHistory->workplace_position_id)) {
                    $salary = $workplacePositionSalaries->get($workHistory->workplace_position_id);
                } elseif ($workplaceShiftSalaries->has($workHistory->workplace_shift_id)) {
                    $salary = $workplaceShiftSalaries->get($workHistory->workplace_shift_id);
                } elseif ($workplaceSalaries->has($workHistory->workplace_id)) {
                    $salary = $workplaceSalaries->get($workHistory->workplace_id);
                }
                if (!$hasSpecificSalary) {
                    $shiftLeaders = $workplaceShiftLeaders[$workHistory->workplace_shift_id] ?? null;
                    if ($shiftLeaders) {
                        $leader = $shiftLeaders->firstWhere('user_id', $userId);
                        if ($leader) {
                            $salary += $leader->bonus_per_hour;
                        }
                    }
                }

                if ($minSalary === -1 || $salary < $minSalary) {
                    $minSalary = $salary;
                }
                if ($maxSalary === -1 || $salary > $maxSalary) {
                    $maxSalary = $salary;
                }

                $minutes =
                    $workHistory->work_minutes_normal +
                    $workHistory->work_minutes_night * 1.25 +
                    $workHistory->work_minutes_overtime * 1.25 +
                    $workHistory->work_minutes_night_overtime * 1.5;

                $amount = bcmul(bcdiv($minutes, 60, 6), $salary);

                $data[$month] = [
                    'total' => bcadd($total, $amount),
                    'min' => $minSalary,
                    'max' => $maxSalary,
                    'total_minutes' => $totalMinutes + $minutes,
                ];
            }

            $allData[$userId] = $data;
        }

        return [$allData, $months];
    }

    /**
     * A settled month must never be rewritten by a later recalculation: the finalized amount
     * was agreed with the employee, and a fully paid one has already been transferred.
     * Recalculations are triggered by ordinary edits (fixing a check-in, marking a shift as
     * absent), which must not move money that is already out the door.
     */
    private function isSalaryLocked(Salary $salary): bool
    {
        return (bool) $salary->is_finalized || (bool) $salary->is_fully_paid;
    }

    /**
     * The main loop only touches months that still have countable work histories, so a month
     * that lost all of them (e.g. every shift got marked as absent) would keep its stale
     * amount. Reset those - but only for months the requested range fully covers, since a
     * partial range says nothing about the rest of the month.
     */
    private function resetSalariesWithoutWorkTime(
        array $userIds,
        Carbon $fromDate,
        Carbon $toDate,
        array $allData
    ): void {
        $coveredMonths = [];
        $cursor = $fromDate->copy()->startOfMonth();
        while ($cursor->lte($toDate)) {
            if ($cursor->gte($fromDate) && $cursor->copy()->endOfMonth()->lte($toDate)) {
                $coveredMonths[] = $cursor->format('Y-m');
            }
            $cursor->addMonth();
        }

        if (empty($coveredMonths)) {
            return;
        }

        Salary::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('month', $coveredMonths)
            ->get()
            ->each(function (Salary $salary) use ($allData) {
                if ($this->isSalaryLocked($salary) || isset($allData[$salary->user_id][$salary->month])) {
                    return;
                }

                $committed = $salary->total_paid + $salary->total_deductions + $salary->total_fee;

                // total_remaining is unsigned and cannot express money already committed to a
                // month that is now worth nothing. Leave the row alone and say so, rather than
                // crash the transaction or silently write off what was paid. User actions that
                // would land here are refused upfront (see ToggleAbsentAction).
                if ($committed > 0) {
                    Log::warning(
                        "[SalaryService] Month {$salary->month} of user {$salary->user_id} lost all work time " .
                            "but has {$committed} already paid/deducted. Salary left untouched for review."
                    );

                    return;
                }

                $salary->update([
                    'total_amount' => 0,
                    'total_remaining' => 0,
                    'min_salary' => 0,
                    'max_salary' => 0,
                    'total_minutes' => 0,
                ]);
            });
    }
}
