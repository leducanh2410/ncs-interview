<?php

namespace App\Console\Commands;

use App\Actions\Admin\User\ReopenEmptiedDocumentStepsAction;
use App\Actions\Admin\User\ResolveWebAdminAccessAction;
use App\Enums\UserPaperStepStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ListEmptiedDocumentSteps extends Command
{
    public const CHUNK_SIZE = 200;

    /**
     * @var string
     */
    protected $signature = 'users:list-emptied-document-steps';

    /**
     * @var string
     */
    protected $description = 'List employees with documents marked as filed whose images are missing, for an admin to reopen';

    public function handle(
        ReopenEmptiedDocumentStepsAction $reopenEmptiedDocumentSteps,
        ResolveWebAdminAccessAction $resolveWebAdminAccess
    ): int {
        $rows = [];

        User::query()
            ->where('is_disabled', false)
            ->whereHas(
                'paper.steps',
                fn($query) => $query->where('status', '!=', UserPaperStepStatus::NeedsReupload->value)
            )
            ->chunkById(self::CHUNK_SIZE, function (Collection $users) use (
                $reopenEmptiedDocumentSteps,
                $resolveWebAdminAccess,
                &$rows
            ) {
                $webAdminIds = $resolveWebAdminAccess->forUsers($users);
                foreach ($users as $user) {
                    if (isset($webAdminIds[$user->id])) {
                        continue;
                    }

                    $resolved = $reopenEmptiedDocumentSteps->resolve($user);
                    if ($resolved['reopen'] === [] && $resolved['clear'] === []) {
                        continue;
                    }

                    $rows[] = [
                        $user->id,
                        $user->staff_no,
                        $user->paper_status,
                        implode(', ', $resolved['reopen']),
                        implode(', ', $resolved['clear']),
                    ];
                }
            });

        if ($rows === []) {
            $this->info('No employee has a document marked as filed with images missing.');

            return Command::SUCCESS;
        }

        $this->table(['ID', 'Staff no', 'Paper status', 'Steps to reopen', 'Steps to clear'], $rows);
        $this->info(count($rows) . ' employees have documents marked as filed with images missing.');

        return Command::SUCCESS;
    }
}
