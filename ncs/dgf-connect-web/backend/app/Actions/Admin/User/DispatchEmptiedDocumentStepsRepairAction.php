<?php

namespace App\Actions\Admin\User;

use App\Enums\UserPaperStepStatus;
use App\Jobs\RepairEmptiedDocumentStepsJob;
use App\Models\User;
use Illuminate\Support\Collection;

class DispatchEmptiedDocumentStepsRepairAction
{
    public const CHUNK_SIZE = 200;

    public function __invoke(): int
    {
        $queuedUsers = 0;

        User::query()
            ->where('is_disabled', false)
            ->whereHas(
                'paper.steps',
                fn($query) => $query->where('status', '!=', UserPaperStepStatus::NeedsReupload->value)
            )
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $users) use (&$queuedUsers) {
                $userIds = $users->pluck('id')->all();
                RepairEmptiedDocumentStepsJob::dispatch($userIds);
                $queuedUsers += count($userIds);
            });

        return $queuedUsers;
    }
}
