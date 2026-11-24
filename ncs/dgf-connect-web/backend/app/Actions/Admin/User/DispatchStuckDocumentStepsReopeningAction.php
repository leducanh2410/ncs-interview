<?php

namespace App\Actions\Admin\User;

use App\Enums\UserPaperStatus;
use App\Jobs\ReopenStuckDocumentStepsJob;
use App\Models\User;
use Illuminate\Support\Collection;

class DispatchStuckDocumentStepsReopeningAction
{
    public const CHUNK_SIZE = 200;

    public function __invoke(): int
    {
        $queuedUsers = 0;

        User::query()
            ->where('paper_status', UserPaperStatus::Pending->value)
            ->where('is_disabled', false)
            ->whereHas('paper')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $users) use (&$queuedUsers) {
                $userIds = $users->pluck('id')->all();
                ReopenStuckDocumentStepsJob::dispatch($userIds);
                $queuedUsers += count($userIds);
            });

        return $queuedUsers;
    }
}
