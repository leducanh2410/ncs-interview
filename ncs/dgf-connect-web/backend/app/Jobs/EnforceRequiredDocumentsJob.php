<?php

namespace App\Jobs;

use App\Actions\Admin\User\RequestDocumentCompletionAction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EnforceRequiredDocumentsJob implements ShouldQueue
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

    public function handle(RequestDocumentCompletionAction $requestDocumentCompletion): void
    {
        $users = User::query()->whereIn('id', $this->userIds)->with('info')->get();

        $firstFailure = null;
        $failedUserIds = [];

        foreach ($users as $user) {
            try {
                $result = $requestDocumentCompletion($user);
            } catch (Throwable $e) {
                Log::error('Enforcing required documents failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $firstFailure ??= $e;
                $failedUserIds[] = $user->id;

                continue;
            }

            if ($result->getError() === null) {
                Log::info('Employee sent back to complete required documents', [
                    'user_id' => $user->id,
                    'reopened_steps' => $result->getData()['reopened_steps'],
                ]);
            }
        }

        if ($firstFailure !== null) {
            throw new RuntimeException(
                'Enforcing required documents failed for users: ' . implode(', ', $failedUserIds),
                previous: $firstFailure
            );
        }
    }
}
