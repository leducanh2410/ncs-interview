<?php

namespace App\Jobs;

use App\Actions\Admin\Group\RecomputeFrequentUrgentWorkerGroupAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keeps the "often takes urgent shifts" group fresh for admins browsing the group screens.
 *
 * Membership is bounded by a trailing 90-day window, so an employee can drop out purely
 * because a day passed — with no observer event to react to. A daily pass is enough to
 * track that: the window moves at day granularity, so nothing changes more often.
 *
 * Recipient resolution does not depend on this job. A recruitment notification that opts
 * into the group recomputes it inline first, so targeting never reads a stale roster even
 * if this job has not run since the last qualifying shift.
 */
class RecomputeFrequentUrgentWorkerGroup implements ShouldQueue
{
    use Queueable;

    public function handle(RecomputeFrequentUrgentWorkerGroupAction $recompute): void
    {
        $recompute();
    }
}
