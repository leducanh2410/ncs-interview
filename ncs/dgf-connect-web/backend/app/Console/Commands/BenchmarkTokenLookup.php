<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchmarkTokenLookup extends Command
{
    protected $signature = 'benchmark:token-lookup
                            {--iterations=1000 : Number of iterations to run}
                            {--warmup=100 : Number of warmup iterations before measuring}
                            {--explain : Show EXPLAIN for both queries}';

    protected $description = 'Benchmark token lookup performance: whereHas vs direct AccessToken lookup';

    public function handle(): void
    {
        $iterations = (int) $this->option('iterations');
        $warmup = (int) $this->option('warmup');
        $showExplain = $this->option('explain');

        $this->info('=== Token Lookup Performance Benchmark ===');
        $this->info("Iterations: {$iterations}, Warmup: {$warmup}");
        $this->newLine();

        // Find an existing valid token
        $token = $this->findValidToken();
        if (!$token) {
            $this->error('No valid tokens found in database');

            return;
        }

        $tokenString = $token->token;
        $this->info("Using existing token: {$tokenString}");
        $this->info("Token belongs to user ID: {$token->user_id}");
        $this->newLine();

        // Show the actual SQL queries
        $this->showQueries($tokenString);

        // Show EXPLAIN if requested
        if ($showExplain) {
            $this->showExplain($tokenString);
        }

        $this->newLine();

        // Warmup cache
        $this->info('Warming up...');
        $this->warmup($tokenString, $warmup);

        // Benchmark approach 1: whereHas
        $this->info('Benchmarking whereHas approach (1 query with subquery)...');
        $result1 = $this->benchmarkWhereHas($tokenString, $iterations);

        // Clear any caches between tests
        DB::disconnect();
        DB::reconnect();

        // Benchmark approach 2: Direct lookup (eager loaded user)
        $this->info('Benchmarking direct lookup with eager load (1 query with join)...');
        $result2 = $this->benchmarkDirectLookupOptimized($tokenString, $iterations);

        // Results
        $this->displayResults($result1, $result2, $iterations);
    }

    private function findValidToken(): ?AccessToken
    {
        return AccessToken::query()->where('expires_at', '>', now())->inRandomOrder()->first();
    }

    private function showQueries(string $token): void
    {
        $this->info('=== Query Analysis ===');

        // whereHas query
        DB::enableQueryLog();
        User::query()
            ->whereHas('accessTokens', function ($query) use ($token) {
                $query->where('token', $token)->where('expires_at', '>', now());
            })
            ->first();
        $whereHasQuery = DB::getQueryLog()[0]['query'] ?? 'N/A';
        DB::flushQueryLog();

        // Direct lookup with JOIN
        DB::flushQueryLog();
        DB::enableQueryLog();
        AccessToken::query()
            ->select('users.*')
            ->join('users', 'access_tokens.user_id', '=', 'users.id')
            ->where('access_tokens.token', $token)
            ->where('access_tokens.expires_at', '>', now())
            ->first();
        $directJoinQuery = DB::getQueryLog()[0]['query'] ?? 'N/A';
        DB::disableQueryLog();

        $this->warn('whereHas approach (EXISTS):');
        $this->line("  SQL: {$whereHasQuery}");
        $this->line('  Queries executed: 1');
        $this->newLine();

        $this->warn('Direct approach (JOIN):');
        $this->line("  SQL: {$directJoinQuery}");
        $this->line('  Queries executed: 1');
        $this->newLine();
    }

    private function showExplain(string $token): void
    {
        $this->info('=== EXPLAIN Analysis ===');

        // EXPLAIN whereHas
        $whereHasExplain = DB::select(
            'EXPLAIN ' .
                User::query()
                    ->whereHas('accessTokens', function ($query) use ($token) {
                        $query->where('token', $token)->where('expires_at', '>', now());
                    })
                    ->toSql(),
            [$token, now()->toDateTimeString()]
        );

        $this->warn('whereHas (EXISTS) EXPLAIN:');
        $this->table(
            array_keys((array) $whereHasExplain[0]),
            collect($whereHasExplain)->map(fn($row) => (array) $row)->toArray()
        );
        $this->newLine();

        // EXPLAIN direct with JOIN
        $joinQuery = AccessToken::query()
            ->select('users.*')
            ->join('users', 'access_tokens.user_id', '=', 'users.id')
            ->where('access_tokens.token', $token)
            ->where('access_tokens.expires_at', '>', now());

        $joinExplain = DB::select('EXPLAIN ' . $joinQuery->toSql(), $joinQuery->getBindings());

        $this->warn('Direct (JOIN) EXPLAIN:');
        $this->table(
            array_keys((array) $joinExplain[0]),
            collect($joinExplain)->map(fn($row) => (array) $row)->toArray()
        );
    }

    private function warmup(string $token, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            User::query()
                ->whereHas('accessTokens', function ($query) use ($token) {
                    $query->where('token', $token)->where('expires_at', '>', now());
                })
                ->first();

            // Join approach - true single query
            AccessToken::query()
                ->select('users.*')
                ->join('users', 'access_tokens.user_id', '=', 'users.id')
                ->where('access_tokens.token', $token)
                ->where('access_tokens.expires_at', '>', now())
                ->first();
        }
    }

    private function benchmarkWhereHas(string $token, int $iterations): array
    {
        DB::enableQueryLog();
        $startMemory = memory_get_usage(true);
        $startTime = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            User::query()
                ->whereHas('accessTokens', function ($query) use ($token) {
                    $query->where('token', $token)->where('expires_at', '>', now());
                })
                ->first();
        }

        $endTime = hrtime(true);
        $endMemory = memory_get_usage(true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [
            'time_ms' => ($endTime - $startTime) / 1e6,
            'memory_kb' => ($endMemory - $startMemory) / 1024,
            'queries' => count($queries) / $iterations,
            'avg_time_ms' => ($endTime - $startTime) / 1e6 / $iterations,
        ];
    }

    private function benchmarkDirectLookupOptimized(string $token, int $iterations): array
    {
        DB::enableQueryLog();
        $startMemory = memory_get_usage(true);
        $startTime = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            // Use actual JOIN for single query
            AccessToken::query()
                ->select('users.*')
                ->join('users', 'access_tokens.user_id', '=', 'users.id')
                ->where('access_tokens.token', $token)
                ->where('access_tokens.expires_at', '>', now())
                ->first();
        }

        $endTime = hrtime(true);
        $endMemory = memory_get_usage(true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [
            'time_ms' => ($endTime - $startTime) / 1e6,
            'memory_kb' => ($endMemory - $startMemory) / 1024,
            'queries' => count($queries) / $iterations,
            'avg_time_ms' => ($endTime - $startTime) / 1e6 / $iterations,
        ];
    }

    private function displayResults(array $whereHas, array $direct, int $iterations): void
    {
        $improvement = (($whereHas['time_ms'] - $direct['time_ms']) / $whereHas['time_ms']) * 100;

        $this->newLine();
        $this->info('=== Results ===');
        $this->newLine();

        $headers = ['Metric', 'whereHas (EXISTS)', 'Direct (JOIN)', 'Improvement'];
        $rows = [
            [
                'Total Time',
                round($whereHas['time_ms'], 2) . ' ms',
                round($direct['time_ms'], 2) . ' ms',
                round($improvement, 1) . '%',
            ],
            [
                'Avg per lookup',
                round($whereHas['avg_time_ms'], 3) . ' ms',
                round($direct['avg_time_ms'], 3) . ' ms',
                '-',
            ],
            ['Queries per lookup', round($whereHas['queries'], 1), round($direct['queries'], 1), '-'],
            ['Memory used', round($whereHas['memory_kb'], 2) . ' KB', round($direct['memory_kb'], 2) . ' KB', '-'],
        ];

        $this->table($headers, $rows);

        $this->newLine();
        if ($improvement > 5) {
            $this->info("✓ Direct lookup with JOIN is {$improvement}% faster");
        } elseif ($improvement < -5) {
            $this->warn('✗ whereHas with EXISTS is ' . abs($improvement) . '% faster');
            $this->line('  MySQL optimizer may be handling EXISTS more efficiently than JOIN');
        } else {
            $this->info('  Both approaches have similar performance');
        }

        $whereHasThroughput = round($iterations / ($whereHas['time_ms'] / 1000), 0);
        $directThroughput = round($iterations / ($direct['time_ms'] / 1000), 0);

        $this->newLine();
        $this->info('Throughput:');
        $this->info("  whereHas (EXISTS): {$whereHasThroughput} lookups/sec");
        $this->info("  Direct (JOIN):     {$directThroughput} lookups/sec");
    }
}
