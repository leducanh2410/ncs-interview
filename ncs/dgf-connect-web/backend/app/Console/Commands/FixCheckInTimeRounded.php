<?php

namespace App\Console\Commands;

use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FixCheckInTimeRounded extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:check-in-time-rounded {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $chunks = CheckIn::query()
            ->with('shift.workplace', 'shift.workplaceShift', 'shiftUser')
            ->where(function ($query) {
                $query->whereNull('check_out_time_rounded')->orWhereNull('check_in_time_rounded');
            })
            ->whereNotNull('check_out_time')
            ->whereNotNull('checked_in_by')
            ->whereNotNull('checked_out_by')
            ->lazy()
            ->chunk(100);

        $count = 0;
        foreach ($chunks as $chunk) {
            foreach ($chunk as $checkIn) {
                $hours = WorkHelper::calculateWorkHour(
                    $checkIn->shift,
                    Carbon::parse($checkIn->check_in_time),
                    Carbon::parse($checkIn->check_out_time),
                    $checkIn->shiftUser->is_overtime_allowed,
                    $checkIn->break_minutes
                );

                if (!$this->option('dry-run')) {
                    $checkIn->update([
                        'check_in_time_rounded' => $hours['check_in_time'],
                        'check_out_time_rounded' => $hours['check_out_time'],
                    ]);
                }
            }

            $count += $chunk->count();

            $this->info("Fixed {$count} records");
        }
    }
}
