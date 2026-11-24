<?php

namespace App\Console\Commands;

use App\Actions\Admin\User\DispatchRequiredDocumentsEnforcementAction;
use App\Actions\Admin\User\ResolveDocumentCompletionRequestAction;
use App\Models\User;
use Illuminate\Console\Command;

class EnforceRequiredDocuments extends Command
{
    /**
     * @var string
     */
    protected $signature = 'users:enforce-required-documents
        {--dry-run : List the employees that would be sent back without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Send every approved employee whose file lacks a required document back to pending';

    public function handle(
        DispatchRequiredDocumentsEnforcementAction $dispatchEnforcement,
        ResolveDocumentCompletionRequestAction $resolveDocumentCompletionRequest
    ): int {
        if (!$this->option('dry-run')) {
            $dispatched = $dispatchEnforcement();
            $this->info("Queued {$dispatched} approved employees for a required documents check.");

            return Command::SUCCESS;
        }

        $this->warn('DRY RUN MODE - No changes will be made');

        $rows = [];
        $dispatchEnforcement->eachChunkOfUsersOwingDocuments(function (array $userIds) use (
            $resolveDocumentCompletionRequest,
            &$rows
        ) {
            foreach (User::query()->whereIn('id', $userIds)->with('info')->get() as $user) {
                $result = $resolveDocumentCompletionRequest($user);
                if ($result->getError() !== null) {
                    continue;
                }

                $rows[] = [
                    $user->id,
                    $user->staff_no,
                    $user->short_name,
                    implode(', ', $result->getData()['missing_steps']),
                    $result->getData()['student_card_expiry_missing'] ? 'yes' : 'no',
                ];
            }
        });

        if ($rows === []) {
            $this->info('Every approved employee already holds the required documents.');

            return Command::SUCCESS;
        }

        $this->table(['ID', 'Staff no', 'Name', 'Missing steps', 'Student card expiry missing'], $rows);
        $this->info(count($rows) . ' employees would be sent back to pending.');

        return Command::SUCCESS;
    }
}
