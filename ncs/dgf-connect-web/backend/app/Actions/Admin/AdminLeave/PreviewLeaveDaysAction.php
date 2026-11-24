<?php

namespace App\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Services\AdminLeave\LeaveDayCalculator;
use Illuminate\Support\Carbon;

/**
 * Lets a client show the day count live while the user is still picking dates. It runs the same
 * calculator submission runs, so the previewed number is the number that will be stored — the
 * whole point of the endpoint is that the rules are not duplicated client-side.
 */
class PreviewLeaveDaysAction
{
    public function __construct(private readonly LeaveDayCalculator $calculator) {}

    public function __invoke(string $startDate, string $endDate): ActionResult
    {
        // Creates nothing, so it is safe to call on every date change. On-demand year generation
        // still applies; that side effect is idempotent and makes the later submission agree.
        $leaveDays = $this->calculator->countDays(Carbon::parse($startDate), Carbon::parse($endDate));

        return new ActionResult(data: ['leave_days' => $leaveDays]);
    }
}
