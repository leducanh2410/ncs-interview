<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchmarkIndexPerformance extends Command
{
    protected $signature = 'benchmark:index-performance {--iterations=100}';

    protected $description = 'Benchmark query performance for index optimization';

    private array $queries = [
        'shift_users_user_status' => [
            'name' => 'shift_users [user_id, status]',
            'sql' => "SELECT * FROM shift_users WHERE user_id = ? AND status = 'approved' LIMIT 1",
            'params' => [15315],
        ],
        'shift_users_with_join' => [
            'name' => 'shift_users with JOIN (getCheckInShift)',
            'sql' => "SELECT shift_users.* FROM shift_users 
                      INNER JOIN shifts ON shifts.id = shift_users.shift_id 
                      WHERE shift_users.user_id = ? 
                      AND shift_users.status = 'approved' 
                      AND shifts.date BETWEEN ? AND ?",
            'params' => [15315, '2025-03-11', '2025-03-13'],
        ],
        'shifts_workplace_date' => [
            'name' => 'shifts [workplace_id, date]',
            'sql' => 'SELECT * FROM shifts WHERE workplace_id = ? AND date = ? LIMIT 1',
            'params' => [1, '2025-03-12'],
        ],
        'shifts_order_by_date' => [
            'name' => 'shifts ORDER BY date',
            'sql' => 'SELECT * FROM shifts WHERE workplace_id = ? ORDER BY date DESC LIMIT 50',
            'params' => [1],
        ],
        'check_ins_user_time' => [
            'name' => 'check_ins [user_id, check_in_time]',
            'sql' => 'SELECT * FROM check_ins WHERE user_id = ? ORDER BY check_in_time DESC LIMIT 20',
            'params' => [3649],
        ],
        'check_ins_history' => [
            'name' => 'check_ins history with date range',
            'sql' => "SELECT check_ins.* FROM check_ins 
                      INNER JOIN shift_users ON shift_users.id = check_ins.shift_user_id 
                      INNER JOIN shifts ON shifts.id = shift_users.shift_id 
                      WHERE check_ins.user_id = ? 
                      AND shifts.date BETWEEN ? AND ?
                      ORDER BY check_ins.check_in_time DESC",
            'params' => [3649, '2025-01-01', '2025-03-31'],
        ],
    ];

    public function handle(): int
    {
        $iterations = (int) $this->option('iterations');

        $this->info("Running benchmark with {$iterations} iterations per query...\n");

        $results = [];

        foreach ($this->queries as $key => $query) {
            $this->info("Benchmarking: {$query['name']}");

            $times = [];

            for ($i = 0; $i < $iterations; $i++) {
                $start = microtime(true);
                DB::select($query['sql'], $query['params']);
                $times[] = (microtime(true) - $start) * 1000;
            }

            $avg = array_sum($times) / count($times);
            $min = min($times);
            $max = max($times);
            $p95 = $this->percentile($times, 95);

            $results[$key] = [
                'name' => $query['name'],
                'avg_ms' => round($avg, 3),
                'min_ms' => round($min, 3),
                'max_ms' => round($max, 3),
                'p95_ms' => round($p95, 3),
            ];

            $this->line(
                "  Avg: {$results[$key]['avg_ms']}ms, Min: {$results[$key]['min_ms']}ms, Max: {$results[$key]['max_ms']}ms, P95: {$results[$key]['p95_ms']}ms\n"
            );
        }

        $this->printExplainAnalysis();

        $this->info("\n=== Summary ===");
        $this->table(
            ['Query', 'Avg (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)'],
            array_map(fn($r) => [$r['name'], $r['avg_ms'], $r['min_ms'], $r['max_ms'], $r['p95_ms']], $results)
        );

        return self::SUCCESS;
    }

    private function percentile(array $values, float $percentile): float
    {
        sort($values);
        $index = (count($values) - 1) * ($percentile / 100);
        $lower = floor($index);
        $upper = ceil($index);
        if ($lower === $upper) {
            return $values[(int) $index];
        }

        return $values[(int) $lower] * ($upper - $index) + $values[(int) $upper] * ($index - $lower);
    }

    private function printExplainAnalysis(): void
    {
        $this->info("\n=== EXPLAIN Analysis ===");

        foreach ($this->queries as $query) {
            $this->info("\n{$query['name']}:");
            $result = DB::select("EXPLAIN {$query['sql']}", $query['params']);
            foreach ($result as $row) {
                $row = (array) $row;
                $this->line(
                    "  table: {$row['table']}, type: {$row['type']}, key: {$row['key']}, rows: {$row['rows']}, Extra: {$row['Extra']}"
                );
            }
        }
    }
}
