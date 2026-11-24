<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
use App\Mail\PortraitImageRequiredEmail;
use App\Models\User;
use App\Models\UserFace;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use App\Services\FaceRecognitionService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RegisterFaceBatch extends Command
{
    protected $signature = 'face:register-batch {--max=50 : Maximum number of users to process} {--send-email : Send rejection email notifications to users} {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Register user faces to current recognition service in batch';

    private int $successCount = 0;

    private int $skippedCount = 0;

    private int $failedCount = 0;

    private array $failedUsers = [];

    public function __construct(private FaceRecognitionService $faceRecognitionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $max = min((int) $this->option('max'), 1000);
        $currentProvider = config('services.face_recognition.provider');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting batch face registration...');
        $this->info("Current provider: {$currentProvider}");
        $this->info("Processing max {$max} users...");
        $this->newLine();

        // Query eligible users
        $users = User::query()
            ->where('paper_status', UserPaperStatus::Approved->value)
            ->where('is_disabled', false)
            ->whereDoesntHave('userFaces', function ($query) use ($currentProvider) {
                $query->where('provider', $currentProvider);
            })
            ->whereHas('roles', function ($query) {
                $query->where('name', RoleName::User->value);
            })
            ->limit($max)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No eligible users found. All done.');

            return Command::FAILURE;
        }

        $userIds = $users->pluck('id')->toArray();

        // Get all approved portraits for these users at once, ordered by approved_at
        // Use keyBy so later images overwrite earlier ones (keeping only the most recent per user)
        $portraits = UserPaperItem::query()
            ->join('user_papers', 'user_paper_items.user_paper_id', '=', 'user_papers.id')
            ->whereIn('user_papers.user_id', $userIds)
            ->where('user_paper_items.type', UserPaperType::PortraitImage->value)
            ->whereNotNull('user_paper_items.approved_at')
            ->whereNull('user_paper_items.rejected_at')
            ->orderBy('user_paper_items.approved_at')
            ->select('user_paper_items.*', 'user_papers.user_id')
            ->get()
            ->keyBy('user_id');

        $this->info("Found {$users->count()} users to process:");
        foreach ($users as $user) {
            $hasPortrait = $portraits->has($user->id);
            $status = $hasPortrait ? '✓ has portrait' : '✗ no portrait (will be rejected)';
            $this->line("  - {$user->id}: {$user->short_name} ({$user->email}) - {$status}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run - no changes will be made.');
            $this->newLine();
            $this->processBatch($users, $portraits, $currentProvider, $dryRun);

            return $this->outputSummary();
        }

        $this->newLine();

        if (!$this->option('no-interaction') && !$this->confirm('Do you want to process these users?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->newLine();

        $this->processBatch($users, $portraits, $currentProvider, $dryRun);

        return $this->outputSummary();
    }

    /**
     * @throws Throwable
     */
    private function processBatch(Collection $users, Collection $portraits, string $currentProvider, bool $dryRun): void
    {
        $batchItems = [];
        $userPortraitMap = [];

        foreach ($users as $user) {
            $portrait = $portraits->get($user->id);

            if (!$portrait) {
                // User is approved but has no portrait image - reject them
                $this->rejectUserWithoutPortrait($user, $dryRun);

                continue;
            }

            // Add to batch
            $batchItems[] = [
                'user_id' => (string) $user->id,
                'image_url' => $portrait->file_path,
            ];

            $userPortraitMap[$user->id] = $portrait;
        }

        if (empty($batchItems)) {
            return;
        }

        if ($dryRun) {
            // In dry-run mode, just simulate successful registrations
            foreach ($userPortraitMap as $userId => $portrait) {
                $this->successCount++;
                $user = $users->firstWhere('id', $userId);
                $this->line("✓ Would register user #{$userId} ({$user->email})");
            }

            return;
        }

        try {
            // Register faces with recognition service in batch
            $result = $this->faceRecognitionService->addUserBatch($batchItems);

            // Process results
            foreach ($result->results as $item) {
                $userId = (int) $item['user_id'];
                $user = $users->firstWhere('id', $userId);
                $portrait = $userPortraitMap[$userId] ?? null;

                if (!$user || !$portrait) {
                    continue;
                }

                if ($item['success']) {
                    // Create UserFace record
                    UserFace::query()->create([
                        'user_id' => $userId,
                        'user_paper_item_id' => $portrait->id,
                        'vector_id' => $item['vector_id'] ?? null,
                        'provider' => $currentProvider,
                    ]);

                    $this->successCount++;
                    $this->line("✓ Registered user #{$userId} ({$user->email})");
                } else {
                    // Check if failure is due to an invalid image or service error
                    // Doesn't happen in dry mode
                    $isInvalidImage = $item['is_invalid_image'] ?? false;

                    if ($isInvalidImage) {
                        // Image quality issue - reject the portrait
                        $this->handleRegistrationFailure(
                            $user,
                            $portrait,
                            $item['error'] ?? 'Recognition provider returned failure',
                            false
                        );
                    } else {
                        // Service error - don't reject the portrait, just count as failed
                        $this->failedCount++;
                        $failureReason = ($item['error'] ?? 'Service error') . ' (portrait not rejected)';
                        $this->failedUsers[] = [
                            'user_id' => $user->id,
                            'reason' => $failureReason,
                        ];
                        $this->line('✗ User ' . "#{$user->id}: {$failureReason}");

                        Log::warning('Face registration failed due to service error, portrait not rejected', [
                            'user_id' => $user->id,
                            'paper_item_id' => $portrait->id,
                            'reason' => $item['error'] ?? 'Service error',
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            // If a batch fails entirely, mark all as failed
            foreach ($userPortraitMap as $userId => $portrait) {
                $user = $users->firstWhere('id', $userId);
                if ($user) {
                    $this->handleRegistrationFailure($user, $portrait, $e->getMessage(), $dryRun);
                }
            }

            Log::error('Batch face registration failed', [
                'error' => $e->getMessage(),
                'user_count' => count($batchItems),
            ]);
        }
    }

    /**
     * @throws Throwable
     */
    private function handleRegistrationFailure(User $user, UserPaperItem $portrait, string $reason, bool $dryRun): void
    {
        $statusChange = null;
        $userRejected = false;

        // Check if the user has any approved portrait images left (other than this one)
        $hasApprovedPortrait = UserPaperItem::query()
            ->where('user_paper_id', $portrait->user_paper_id)
            ->where('type', UserPaperType::PortraitImage->value)
            ->where('id', '!=', $portrait->id)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->exists();

        // Determine if a user would be rejected
        if (!$hasApprovedPortrait) {
            $statusChange = 'Paper status changed to rejected';
            $userRejected = true;
        }

        if (!$dryRun) {
            DB::transaction(function () use ($portrait, $user, $userRejected) {
                $rejectedNote =
                    'Face recognition failed. Please upload a portrait photo with good quality. The photo should clearly show your face with good lighting and no obstructions.';
                // Reject the portrait item
                $portrait->update(['rejected_at' => now()]);

                // If no approved portraits left, reject the user
                if ($userRejected) {
                    // Update user paper with rejection note
                    UserPaper::query()
                        ->where('id', $portrait->user_paper_id)
                        ->update(['rejected_note' => $rejectedNote]);

                    // Change paper_status to rejected
                    $user->update(['paper_status' => UserPaperStatus::Rejected]);
                }
            });

            // Send email if the option is enabled and the user was rejected
            if ($userRejected && $this->option('send-email')) {
                try {
                    Mail::to($user->email)->send(new PortraitImageRequiredEmail($user));
                    $this->line("  - Email sent to {$user->email}");
                } catch (Throwable $e) {
                    $this->error("  - Failed to send email to {$user->email}: {$e->getMessage()}");
                }
            }

            Log::warning('Face registration failed - portrait rejected', [
                'user_id' => $user->id,
                'paper_item_id' => $portrait->id,
                'reason' => $reason,
                'status_change' => $statusChange,
                'user_rejected' => $userRejected,
            ]);
        }

        $this->failedCount++;
        $failureReason = $reason . ($statusChange ? " ({$statusChange})" : '');
        $this->failedUsers[] = [
            'user_id' => $user->id,
            'reason' => $failureReason,
        ];
        $this->line(($dryRun ? '✗ Would fail user ' : '✗ User ') . "#{$user->id}: {$failureReason}");
    }

    /**
     * @throws Throwable
     */
    private function rejectUserWithoutPortrait(User $user, bool $dryRun): void
    {
        $reason = 'No approved portrait image';
        $statusChange = 'Paper status changed to rejected';

        if (!$dryRun) {
            DB::transaction(function () use ($user) {
                $rejectedNote = 'No portrait image found. Please upload a portrait photo.';
                // Find user paper to update rejection note
                $userPaper = UserPaper::query()->where('user_id', $user->id)->first();

                $userPaper?->update(['rejected_note' => $rejectedNote]);

                // Change paper_status to rejected
                $user->update(['paper_status' => UserPaperStatus::Rejected]);
            });

            // Send email if option is enabled
            if ($this->option('send-email')) {
                try {
                    Mail::to($user->email)->send(new PortraitImageRequiredEmail($user));
                    $this->line("  - Email sent to {$user->email}");
                } catch (Throwable $e) {
                    $this->error("  - Failed to send email to {$user->email}: {$e->getMessage()}");
                }
            }

            Log::warning('User rejected - no portrait image', [
                'user_id' => $user->id,
                'reason' => $reason,
            ]);
        }

        $this->failedCount++;
        $failureReason = $reason . " ({$statusChange})";
        $this->failedUsers[] = [
            'user_id' => $user->id,
            'reason' => $failureReason,
        ];
        $this->line(($dryRun ? '✗ Would reject user ' : '✗ User ') . "#{$user->id}: {$failureReason}");
    }

    private function outputSummary(): int
    {
        $this->newLine();

        if ($this->failedCount > 0) {
            $this->error("✗ Failed {$this->failedCount} users:");
            foreach ($this->failedUsers as $failed) {
                $this->line("  • User #{$failed['user_id']}: {$failed['reason']}");
            }
            $this->newLine();
        }

        $total = $this->successCount + $this->skippedCount + $this->failedCount;

        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Successfully registered', $this->successCount],
                ['Skipped', $this->skippedCount],
                ['Failed', $this->failedCount],
                ['Total processed', $total],
            ]
        );

        return self::SUCCESS;
    }
}
