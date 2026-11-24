<?php

namespace App\Actions\Common\Salary;

use App\Actions\ActionResult;
use App\Facades\SalaryFacade;
use App\Models\Salary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CollectUserSalaryAction
{
    public const CACHE_KEY_PREFIX = 'salary_request_amount_';

    public function __invoke(int $userId, bool $useCache = true): ActionResult
    {
        if ($useCache) {
            $amount = Cache::get(self::CACHE_KEY_PREFIX . $userId);
            if ($amount) {
                return new ActionResult(
                    data: [
                        'amount' => $amount,
                        'salaries' => SalaryFacade::getUnpaidSalaries($userId),
                    ]
                );
            }
        }

        $minMonth = Salary::query()->where('user_id', $userId)->where('is_completed', false)->min('month');
        $from = config('salary.start_date');
        if ($minMonth && $minMonth > $from) {
            $from = "{$minMonth}-01";
        }
        $from = Carbon::parse($from);

        SalaryFacade::collectUsersSalary([$userId], $from, Carbon::now());

        $salaries = SalaryFacade::getUnpaidSalaries($userId);

        $amount = SalaryFacade::getMinMaxForRequest($salaries);

        Cache::put(self::CACHE_KEY_PREFIX . $userId, $amount, 3600);

        return new ActionResult(
            data: [
                'amount' => $amount,
                'salaries' => $salaries,
            ]
        );
    }
}
