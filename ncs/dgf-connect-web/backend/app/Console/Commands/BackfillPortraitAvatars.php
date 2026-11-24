<?php

namespace App\Console\Commands;

use App\Actions\Admin\User\SyncUserAvatarFromPortraitAction;
use App\Enums\UserPaperType;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-off catch-up for employees approved before the avatar sync existed: their
 * portrait was approved without ever reaching the account, and nothing would fix
 * that until their next document renewal.
 *
 * Only users without a picture are touched. That is a blast-radius rule for a batch
 * run, not a promise about intent: `profile_picture` is null both for someone who
 * never set one and for someone who deleted theirs, and nothing records which.
 * Filling in the second case matches what their next portrait approval would do
 * anyway - the sync always overwrites - so this only brings it forward, and only for
 * accounts that are showing nothing today.
 */
class BackfillPortraitAvatars extends Command
{
    protected $signature = 'avatar:backfill-portraits
        {--dry-run : List the users that would be updated without writing anything}
        {--chunk=200 : Number of users to load per chunk}';

    protected $description = 'Set the avatar from the newest approved portrait for users who have none (idempotent).';

    public function handle(SyncUserAvatarFromPortraitAction $syncUserAvatarFromPortraitAction): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $this->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry-run] ' : '';

        // Users who already have a picture are skipped on purpose: see the class docblock.
        // The portrait filter only narrows the candidates so the run and its dry-run
        // report stay meaningful; the action re-checks and has the final say.
        $query = User::query()
            ->whereNull('profile_picture')
            ->whereHas(
                'paper.items',
                fn($q) => $q
                    ->where('type', UserPaperType::PortraitImage->value)
                    ->whereNotNull('approved_at')
                    ->whereNull('rejected_at')
                    ->whereNull('deleted_at')
            );

        $total = $query->count();

        $this->info("{$prefix}Users without a picture but with an approved portrait: {$total}");

        $updated = 0;
        $candidates = 0;
        $failed = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use (
                $syncUserAvatarFromPortraitAction,
                $dryRun,
                &$updated,
                &$candidates,
                &$failed
            ) {
                foreach ($users as $user) {
                    if ($dryRun) {
                        // Id is enough to trace an employee here; emails would put the
                        // whole workforce into a console or CI log.
                        $line = "  candidate #{$user->id}";
                        if ($this->getOutput()->isVerbose()) {
                            $line .= " {$user->email}";
                        }
                        $this->line($line);
                        $candidates++;

                        continue;
                    }

                    try {
                        $result = $syncUserAvatarFromPortraitAction($user);

                        if ($result->getData() === true) {
                            $updated++;
                        } else {
                            $failed++;
                            $this->error("  no avatar for #{$user->id}: portrait unusable, see logs");
                        }
                    } catch (Throwable $e) {
                        // One broken portrait must not abort the whole run.
                        $failed++;
                        $this->error("  failed #{$user->id}: {$e->getMessage()}");
                    }
                }
            });

        // Deliberately not phrased as "avatars set": a dry run cannot tell whether the
        // portrait file behind a candidate is still readable.
        $this->info($dryRun ? "{$prefix}Candidates: {$candidates}" : "Avatars set: {$updated}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
