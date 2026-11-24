<?php

namespace App\Console\Commands;

use App\Actions\AttendanceData\ExportAttendanceDataAction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class ExportAttendanceData extends Command
{
    protected $signature = 'attendance-data:export
        {--workplace=* : Workplace ID to export (repeat for several workplaces)}
        {--from= : First shift date to export (Y-m-d)}
        {--to= : Last shift date to export (Y-m-d)}
        {--output= : File to write, defaults to a timestamped .jsonl.gz under storage/app/attendance-exports}';

    protected $description = 'Export attendance data (workplaces, shifts, check-ins and the employees, with emails redirected and identity numbers faked) of some workplaces over a date range, for replaying on a dev server with attendance-data:import';

    public function handle(ExportAttendanceDataAction $export): int
    {
        try {
            $from = $this->parseDate('from');
            $to = $this->parseDate('to');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path =
            $this->option('output') ?:
            storage_path(
                'app/attendance-exports/attendance-' .
                    $from->format('Ymd') .
                    '-' .
                    $to->format('Ymd') .
                    '-' .
                    now()->format('YmdHis') .
                    '.jsonl.gz'
            );

        try {
            $counts = $export((array) $this->option('workplace'), $from, $to, $path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Export failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn(int $count, string $table) => [$table, $count])->values()
        );
        $this->info("Exported to {$path}");

        return self::SUCCESS;
    }

    private function parseDate(string $option): Carbon
    {
        $value = (string) $this->option($option);
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (InvalidArgumentException) {
            $date = null;
        }
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$option} must be a date in Y-m-d format.");
        }

        return $date;
    }
}
