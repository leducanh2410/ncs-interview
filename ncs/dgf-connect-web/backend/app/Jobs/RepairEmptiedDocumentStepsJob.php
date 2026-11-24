<?php

namespace App\Jobs;

use App\Actions\Admin\User\ReopenEmptiedDocumentStepsAction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RepairEmptiedDocumentStepsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @param int[] $userIds
     */
    public function __construct(public array $userIds) {}

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ReopenEmptiedDocumentStepsAction $reopenEmptiedDocumentSteps): void
    {
        $users = User::query()->whereIn('id', $this->userIds)->get();

        $firstFailure = null;
        $failedUserIds = [];

        foreach ($users as $user) {
            try {
                $result = $reopenEmptiedDocumentSteps($user, countDeletedRenewals: true);
            } catch (Throwable $e) {
                Log::error('Repairing emptied document steps failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $firstFailure ??= $e;
                $failedUserIds[] = $user->id;

                continue;
            }

            if ($result->getError() === null) {
                Log::info('Emptied document steps repaired', [
                    'user_id' => $user->id,
                    'reopened_steps' => $result->getData()['reopened_steps'],
                    'cleared_steps' => $result->getData()['cleared_steps'],
                ]);
            }
        }

        if ($firstFailure !== null) {
            throw new RuntimeException(
                'Repairing emptied document steps failed for users: ' . implode(', ', $failedUserIds),
                previous: $firstFailure
            );
        }
    }
}
