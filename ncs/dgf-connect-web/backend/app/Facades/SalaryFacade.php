<?php

namespace App\Facades;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void collectUsersSalary(array $userIds, Carbon $fromDate, Carbon $toDate, bool $isReset = false)
 * @method static float previewMonthTotal(int $userId, string $month, ?int $ignoreShiftUserId = null)
 * @method static array getMinMaxForRequest(Collection $salaries)
 * @method static null|array calculateMonthlyAmount(array $finalizedAmounts, array $unfinalizedAmounts, int $totalAmount, bool $isTransfer)
 * @method static Collection getUnpaidSalaries(int $userId)
 */
class SalaryFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'salary-service';
    }
}
