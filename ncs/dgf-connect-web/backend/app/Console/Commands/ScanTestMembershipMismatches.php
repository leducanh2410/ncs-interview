<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScanTestMembershipMismatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workplaces:scan-test-mismatches';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for test-data membership mismatches: test workplaces with non-test users, and normal workplaces with test users.';

    public function handle(): int
    {
        $rows = $this->loadMismatches();

        if ($rows->isEmpty()) {
            $this->info('No test-data membership mismatches found.');

            return Command::SUCCESS;
        }

        $testWorkplaceCount = $rows->where('type', 'test_workplace_with_non_test_user')->count();
        $normalWorkplaceCount = $rows->where('type', 'normal_workplace_with_test_user')->count();

        $this->warn("Found {$rows->count()} mismatched membership(s):");
        $this->line("  test workplace with non-test user: {$testWorkplaceCount}");
        $this->line("  normal workplace with test user:   {$normalWorkplaceCount}");
        $this->newLine();

        $this->table(['type', 'workplace_id', 'workplace', 'user_id', 'user'], $this->formatRows($rows));

        return Command::SUCCESS;
    }

    /**
     * Query every workplace membership where the user's is_test flag differs
     * from the workplace's is_test flag.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function loadMismatches(): Collection
    {
        $rows = DB::table('workplace_users')
            ->join('users', 'users.id', '=', 'workplace_users.user_id')
            ->join('workplaces', 'workplaces.id', '=', 'workplace_users.workplace_id')
            ->whereColumn('users.is_test', '!=', 'workplaces.is_test')
            ->orderBy('workplaces.id')
            ->orderBy('users.id')
            ->get([
                'workplaces.id as workplace_id',
                'workplaces.name as workplace_name',
                'workplaces.is_test as workplace_is_test',
                'users.id as user_id',
                'users.last_name as user_last_name',
                'users.first_name as user_first_name',
                'users.email as user_email',
                'users.is_test as user_is_test',
            ]);

        return $rows->map(
            fn($row) => [
                'type' => (bool) $row->workplace_is_test
                    ? 'test_workplace_with_non_test_user'
                    : 'normal_workplace_with_test_user',
                'workplace_id' => (int) $row->workplace_id,
                'workplace_name' => (string) $row->workplace_name,
                'user_id' => (int) $row->user_id,
                'user_name' => mb_trim(((string) $row->user_last_name) . ' ' . ((string) $row->user_first_name)),
            ]
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function formatRows(Collection $rows): array
    {
        $labels = [
            'test_workplace_with_non_test_user' => 'test wp / non-test user',
            'normal_workplace_with_test_user' => 'normal wp / test user',
        ];

        return $rows
            ->map(
                fn(array $row) => [
                    $labels[$row['type']] ?? $row['type'],
                    $row['workplace_id'],
                    $row['workplace_name'],
                    $row['user_id'],
                    $row['user_name'],
                ]
            )
            ->all();
    }
}
