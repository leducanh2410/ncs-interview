<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Mail\PortraitImageRequiredEmail;
use App\Models\User;
use App\Models\UserPaper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class RejectUsersWithoutPortraitImage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:reject-without-portrait
        {--dry-run : Show what would be updated without making changes}
        {--max=100 : Maximum number of users to process (max 500)}
        {--send-email : Send rejection email notifications to users}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reject users who have approved paper_status but no approved portrait image';

    public function handle(): int
    {
        $max = (int) $this->option('max');

        if ($max < 1 || $max > 500) {
            $this->error('The --max option must be between 1 and 500.');

            return Command::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $users = User::query()
            ->where('paper_status', UserPaperStatus::Approved->value)
            ->where('is_disabled', false)
            ->where('email', 'not like', 'del1_________\_%')
            ->whereHas('roles', function ($query) {
                $query->where('name', RoleName::User->value);
            })
            ->whereDoesntHave('paper.items', function ($query) {
                $query->where('type', UserPaperType::PortraitImage->value)->whereNotNull('approved_at');
            });

        $totalCount = (clone $users)->count();
        $users = $users->limit($max)->get();

        if ($users->isEmpty()) {
            $this->info('No users found without approved portrait image.');

            return Command::SUCCESS;
        }

        $this->info(
            "Found {$users->count()} users without approved portrait image (total: {$totalCount}, max: {$max}):"
        );
        foreach ($users as $user) {
            $this->line("  - {$user->id}: {$user->short_name} ({$user->email})");
        }

        if ($dryRun) {
            $this->warn('Dry run - no changes made.');

            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to reject these users?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $rejectedNote =
            'Please upload a portrait photo. The photo should clearly show your face with good lighting and no obstructions.';

        $count = User::query()
            ->whereIn('id', $users->pluck('id')->toArray())
            ->update([
                'paper_status' => UserPaperStatus::Rejected->value,
            ]);

        // Update rejected_note in user_papers table
        $userPaperIds = $users->pluck('paper.id');
        UserPaper::query()
            ->whereIn('id', $userPaperIds)
            ->update(['rejected_note' => $rejectedNote]);

        $this->info("Successfully rejected {$count} users.");

        if ($this->option('send-email')) {
            $this->info('Sending rejection emails...');
            $sentCount = 0;

            foreach ($users as $user) {
                try {
                    Mail::to($user->email)->send(new PortraitImageRequiredEmail($user));
                    $sentCount++;
                    $this->line("  - Email sent to {$user->email}");
                } catch (\Throwable $e) {
                    $this->error("  - Failed to send email to {$user->email}: {$e->getMessage()}");
                }
            }

            $this->info("Emails sent: {$sentCount}/{$count}");
        }

        return Command::SUCCESS;
    }
}
