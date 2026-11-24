<?php

namespace App\Console\Commands;

use App\Enums\UserPaperType;
use App\Models\UserPaperItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateDuplicatePortraitImages extends Command
{
    protected $signature = 'papers:migrate-duplicate-portraits {--dry-run : Show what would be done without making changes} {--max=100 : Maximum number of users to process (max 500)}';

    protected $description = 'Migrate duplicate portrait images - keep only the newest as approved, reject others';

    public function handle(): int
    {
        $this->info('Starting migration of duplicate portrait images...');

        $max = (int) $this->option('max');

        if ($max < 1 || $max > 500) {
            $this->error('The --max option must be between 1 and 500.');

            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $bar = $this->output->createProgressBar();

        // Find all users with 2+ approved portrait images
        $usersWithDuplicates = DB::query()
            ->from('user_paper_items as upi')
            ->join('user_papers as up', 'upi.user_paper_id', '=', 'up.id')
            ->where('upi.type', UserPaperType::PortraitImage->value)
            ->whereNotNull('upi.approved_at')
            ->whereNull('upi.rejected_at')
            ->groupBy('up.user_id')
            ->havingRaw('COUNT(upi.id) >= 2')
            ->limit($max)
            ->pluck('up.user_id');

        $this->newLine();
        $this->info(
            'Found ' . $usersWithDuplicates->count() . " users with duplicate approved portraits (max: {$max})"
        );
        $this->newLine();

        $processedCount = 0;
        $rejectedCount = 0;

        if ($usersWithDuplicates->count() > 0) {
            $bar->start();

            foreach ($usersWithDuplicates as $userId) {
                // Get all approved portrait images for this user, ordered by id (newest last)
                $portraitItems = UserPaperItem::query()
                    ->whereHas('userPaper', fn($q) => $q->where('user_id', $userId))
                    ->where('type', UserPaperType::PortraitImage->value)
                    ->whereNotNull('approved_at')
                    ->whereNull('rejected_at')
                    ->orderBy('id')
                    ->get();

                if ($portraitItems->count() < 2) {
                    $bar->advance();

                    continue;
                }

                // Keep the newest (last) one as approved, reject all others
                $approvedItem = $portraitItems->pop();
                $itemsToReject = $portraitItems;

                if (!$this->option('dry-run')) {
                    foreach ($itemsToReject as $item) {
                        // Set rejected_at for older portraits, keeping approved_at for historical record
                        $item->update(['rejected_at' => now()]);
                        $rejectedCount++;
                    }
                } else {
                    $rejectedCount += $itemsToReject->count();
                }

                $this->newLine();
                $this->line(
                    "User {$userId}: kept portrait #{$approvedItem->id}, reject " .
                        $itemsToReject->count() .
                        ' older portraits'
                );
                foreach ($itemsToReject as $item) {
                    $this->line("  - Will reject portrait #{$item->id} (approved at: {$item->approved_at})");
                }

                $processedCount++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        $this->info('Summary:');
        $this->info("  Users to process: {$processedCount}");
        $this->info("  Total portraits to reject: {$rejectedCount}");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('This was a DRY RUN. To apply changes, run without --dry-run option.');
        }

        return Command::SUCCESS;
    }
}
