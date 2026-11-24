<?php

namespace App\Console\Commands;

use App\Models\FixedSchedule;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConvertWorkplaceToTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workplaces:convert-to-test
        {workplace : ID of the normal workplace to convert}
        {userIds* : User IDs to keep and convert to test users}
        {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert a normal workplace into a test workplace. The given users are flagged as test users and kept; every other non-test member is removed.';

    public function handle(): int
    {
        $workplaceId = (int) $this->argument('workplace');
        $userIds = $this->parseUserIds((array) $this->argument('userIds'));
        $dryRun = (bool) $this->option('dry-run');

        if (empty($userIds)) {
            $this->error('At least one user ID must be specified.');

            return Command::FAILURE;
        }

        /** @var Workplace|null $workplace */
        $workplace = Workplace::query()->find($workplaceId);
        if (!$workplace) {
            $this->error("Workplace with id {$workplaceId} was not found.");

            return Command::FAILURE;
        }

        if ((bool) $workplace->is_test) {
            $this->error("Workplace \"{$workplace->name}\" (id {$workplace->id}) is already a test workplace.");

            return Command::FAILURE;
        }

        $missingIds = $this->missingUserIds($userIds);
        if (!empty($missingIds)) {
            $this->error('User(s) not found: ' . implode(', ', $missingIds));

            return Command::FAILURE;
        }

        $members = $this->loadMembers($workplace);
        [$toConvert, $alreadyTest, $toRemove, $nonMemberKeep] = $this->partition($members, $userIds);

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $this->info("Converting normal workplace \"{$workplace->name}\" (id {$workplace->id}) into a test workplace.");
        $this->printSummary($toConvert, $alreadyTest, $toRemove, $nonMemberKeep);

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run - no changes made.');

            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to proceed with the conversion?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $result = $this->perform($workplace, $userIds, $toRemove->pluck('id')->all());

        $this->newLine();
        $this->info("Workplace \"{$workplace->name}\" is now a test workplace.");
        $this->line('  users flagged as test: ' . count($result['converted_user_ids']));
        $this->line('  members removed:       ' . count($result['removed_user_ids']));

        return Command::SUCCESS;
    }

    /**
     * Flags the given users as test, detaches the other non-test members (with
     * fixed-schedule and future-shift cleanup), and sets the workplace to test.
     *
     * @param  Workplace  $workplace
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $removedUserIds
     * @return array{converted_user_ids: array<int, int>, removed_user_ids: array<int, int>}
     */
    private function perform(Workplace $workplace, array $userIds, array $removedUserIds): array
    {
        DB::transaction(function () use ($workplace, $userIds, $removedUserIds): void {
            if (!empty($userIds)) {
                User::query()
                    ->whereIn('id', $userIds)
                    ->update(['is_test' => true]);
            }

            if (!empty($removedUserIds)) {
                $workplace->users()->detach($removedUserIds);

                FixedSchedule::query()
                    ->where('workplace_id', $workplace->id)
                    ->whereIn('user_id', $removedUserIds)
                    ->delete();

                ShiftUser::query()
                    ->whereIn('user_id', $removedUserIds)
                    ->whereHas('shift', function ($query) use ($workplace): void {
                        $query->where('workplace_id', $workplace->id)->where('date', '>=', Carbon::today());
                    })
                    ->delete();
            }

            $workplace->is_test = true;
            $workplace->save();
        });

        return [
            'converted_user_ids' => array_map('intval', $userIds),
            'removed_user_ids' => array_map('intval', $removedUserIds),
        ];
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array<int, int>
     */
    private function parseUserIds(array $raw): array
    {
        return collect($raw)
            ->map(static function (mixed $value): ?int {
                $value = is_string($value) ? mb_trim($value) : $value;

                return is_numeric($value) ? (int) $value : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function missingUserIds(array $userIds): array
    {
        $existing = User::query()->whereIn('id', $userIds)->pluck('id')->map(fn($id) => (int) $id)->all();

        return array_values(array_diff($userIds, $existing));
    }

    /**
     * @return Collection<int, User>
     */
    private function loadMembers(Workplace $workplace): Collection
    {
        return $workplace
            ->users()
            ->get([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.short_name',
                'users.email',
                'users.is_test',
            ]);
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  array<int, int>  $userIds
     * @return array{0: Collection<int, User>, 1: Collection<int, User>, 2: Collection<int, User>, 3: array<int, int>}
     */
    private function partition(Collection $members, array $userIds): array
    {
        $memberIds = $members->pluck('id')->all();

        $toConvert = $members->whereIn('id', $userIds)->values();
        $nonMemberKeep = array_values(array_diff($userIds, $memberIds));
        $alreadyTest = $members->whereNotIn('id', $userIds)->filter(fn(User $user) => (bool) $user->is_test)->values();
        $toRemove = $members->whereNotIn('id', $userIds)->filter(fn(User $user) => !(bool) $user->is_test)->values();

        return [$toConvert, $alreadyTest, $toRemove, $nonMemberKeep];
    }

    /**
     * @param  Collection<int, User>  $toConvert
     * @param  Collection<int, User>  $alreadyTest
     * @param  Collection<int, User>  $toRemove
     * @param  array<int, int>  $nonMemberKeep
     */
    private function printSummary(
        Collection $toConvert,
        Collection $alreadyTest,
        Collection $toRemove,
        array $nonMemberKeep
    ): void {
        $this->newLine();
        $this->line('<fg=cyan>Users to flag as test users (kept as members):</>');
        if ($toConvert->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'name', 'email'],
                $toConvert->map(fn(User $user) => [$user->id, $user->short_name, $user->email])->all()
            );
        }

        if (!empty($nonMemberKeep)) {
            $this->newLine();
            $this->warn(
                'These specified IDs are not members and will only be flagged as test (not attached): ' .
                    implode(', ', $nonMemberKeep)
            );
        }

        $this->newLine();
        $this->line('<fg=cyan>Members kept (already test users):</>');
        $this->line(
            $alreadyTest->isEmpty()
                ? '  (none)'
                : '  ' . $alreadyTest->map(fn(User $user) => "{$user->id}")->implode(', ')
        );

        $this->newLine();
        $this->line('<fg=cyan>Members to remove (non-test, not specified):</>');
        if ($toRemove->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'name', 'email'],
                $toRemove->map(fn(User $user) => [$user->id, $user->short_name, $user->email])->all()
            );
        }
    }
}
