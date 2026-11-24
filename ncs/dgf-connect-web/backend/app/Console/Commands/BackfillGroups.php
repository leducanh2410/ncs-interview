<?php

namespace App\Console\Commands;

use App\Actions\Admin\Group\BackfillGroupsAction;
use Illuminate\Console\Command;

class BackfillGroups extends Command
{
    protected $signature = 'groups:backfill {--chunk=500 : Number of users to process per chunk}';

    protected $description = 'Ensure system group rows exist and recompute user_groups for every non-locked user (idempotent).';

    public function handle(BackfillGroupsAction $action): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $this->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        $this->info("Starting groups:backfill (chunk={$chunk})...");

        $progress = function (string $stage, int $current, int $total = 0) {
            if ($stage === 'user' && $total > 0 && ($current % 100 === 0 || $current === $total)) {
                $this->line("  users processed: {$current}/{$total}");
            }
        };

        $stats = $action($chunk, $progress);

        $this->info('Backfill complete:');
        $this->line("  workplaces processed:        {$stats['workplaces']}");
        $this->line("  global tier groups ensured:  {$stats['global_tier_groups']}");
        $this->line("  users recomputed:            {$stats['users']}");
        $this->line("  rule-based groups refreshed: {$stats['rule_based_groups']}");

        return self::SUCCESS;
    }
}
