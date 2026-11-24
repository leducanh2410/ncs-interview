<?php

namespace App\Console\Commands;

use App\Actions\Admin\User\ReopenPendingDocumentStepsAction;
use App\Enums\UserPaperStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReopenStuckDocumentSteps extends Command
{
    public const CHUNK_SIZE = 200;

    /**
     * @var string
     */
    protected $signature = 'users:reopen-stuck-document-steps
        {--dry-run : List the employees whose missing documents would be reopened without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Reopen missing documents of pending employees whose app shows them as already filed';

    public function handle(ReopenPendingDocumentStepsAction $reopenPendingDocumentSteps): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $rows = [];
        $failedUserIds = [];

        User::query()
            ->where('paper_status', UserPaperStatus::Pending->value)
            ->where('is_disabled', false)
            ->whereHas('paper')
            ->with('info')
            ->chunkById(self::CHUNK_SIZE, function (Collection $users) use (
                $reopenPendingDocumentSteps,
                $dryRun,
                &$rows,
                &$failedUserIds
            ) {
                foreach ($users as $user) {
                    try {
                        $result = $dryRun
                            ? $reopenPendingDocumentSteps->resolve($user)
                            : $reopenPendingDocumentSteps($user);
                    } catch (Throwable $e) {
                        Log::error('Reopening stuck document steps failed', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                        $failedUserIds[] = $user->id;

                        continue;
                    }

                    if ($result->getError() !== null) {
                        continue;
                    }

                    $rows[] = [
                        $user->id,
                        $user->staff_no,
                        $user->short_name,
                        implode(', ', $result->getData()['reopened_steps']),
                    ];
                }
            });

        if ($rows === []) {
            $this->info('No pending employee is stuck on a document already on file.');
        } else {
            $this->table(['ID', 'Staff no', 'Name', 'Reopened steps'], $rows);
            $this->info(
                count($rows) . ($dryRun ? ' employees would have their documents reopened.' : ' employees reopened.')
            );
        }

        if ($failedUserIds !== []) {
            $this->error(
                count($failedUserIds) .
                    ' employees failed and were left unchanged, run the command again to retry: ' .
                    implode(', ', $failedUserIds)
            );

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
