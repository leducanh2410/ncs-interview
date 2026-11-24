<?php

namespace App\Console\Commands;

use App\Actions\AttendanceData\ImportAttendanceDataAction;
use Illuminate\Console\Command;
use Throwable;

class ImportAttendanceData extends Command
{
    protected $signature = 'attendance-data:import
        {file : File written by attendance-data:export}
        {--fallback-user= : Local user ID to credit for actions of admins not in the file (default: the first global admin)}
        {--dry-run : Run the import and roll it back, only reporting what would change}
        {--force : Import even when the file does not prove it was exported from another server (APP_URL)}';

    protected $description = 'Import a file written by attendance-data:export into this non-production database; rows matching an existing workplace name, user email, ... are overwritten instead of duplicated';

    public function handle(ImportAttendanceDataAction $import): int
    {
        $fallbackUser = $this->option('fallback-user');
        if ($fallbackUser !== null && !ctype_digit((string) $fallbackUser)) {
            $this->error('--fallback-user must be a user ID.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN - the import is rolled back at the end.');
        }

        try {
            $result = $import(
                (string) $this->argument('file'),
                $fallbackUser !== null ? (int) $fallbackUser : null,
                $dryRun,
                (bool) $this->option('force')
            );
        } catch (Throwable $e) {
            $this->error('Import failed, nothing was changed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $meta = $result['meta'];
        $this->info(
            sprintf(
                'Export from %s (%s), workplaces %s, %s to %s',
                $meta['app_env'],
                $meta['exported_at'],
                implode(', ', $meta['workplace_ids']),
                $meta['from'],
                $meta['to']
            )
        );
        $this->table(
            ['Table', 'Inserted', 'Updated'],
            collect($result['tables'])
                ->map(fn(array $stats, string $table) => [$table, $stats['inserted'], $stats['updated']])
                ->values()
        );
        $this->line("Actions of admins outside the file are credited to user #{$result['fallback_user_id']}.");
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
        $this->info($dryRun ? 'Dry run finished, nothing was saved.' : 'Import finished.');

        return self::SUCCESS;
    }
}
