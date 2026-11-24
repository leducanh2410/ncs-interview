<?php

namespace App\Console\Commands;

use App\Helpers\WorkHelper;
use App\Models\CheckIn;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FixWorkHour extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:work-hour {from} {--dry-run}';

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
        $total = CheckIn::query()
            ->whereNotNull('check_out_time')
            ->where('created_at', '>=', $this->argument('from'))
            ->count();
        $chunks = CheckIn::query()
            ->with('shift.workplace', 'shift.workplaceShift', 'shiftUser')
            ->whereNotNull('check_out_time')
            ->where('created_at', '>=', $this->argument('from'))
            ->lazy()
            ->chunk(100);
        $count = 0;
        foreach ($chunks as $chunk) {
            $data = [];
            foreach ($chunk as $checkIn) {
                $hours = WorkHelper::calculateWorkHour(
                    $checkIn->shift,
                    Carbon::parse($checkIn->check_in_time),
                    Carbon::parse($checkIn->check_out_time),
                    $checkIn->shiftUser->is_overtime_allowed,
                    $checkIn->break_minutes
                );

                $data[] = [
                    'id' => $checkIn->id,
                    'shift_user_id' => $checkIn->shift_user_id,
                    'shift_id' => $checkIn->shift_id,
                    'user_id' => $checkIn->user_id,
                    'check_in_time' => $checkIn->check_in_time,
                    'check_in_time_rounded' => $hours['check_in_time'],
                    'check_out_time' => $checkIn->check_out_time,
                    'check_out_time_rounded' => $hours['check_out_time'],
                    'work_minutes' => $hours['total'],
                    'work_minutes_normal' => $hours['normal'],
                    'work_minutes_night' => $hours['night'],
                    'work_minutes_overtime' => $hours['overtime'],
                    'work_minutes_night_overtime' => $hours['night_overtime'],
                ];

                if (!$this->option('dry-run')) {
                    CheckIn::query()->upsert(
                        $data,
                        ['id'],
                        [
                            'shift_user_id',
                            'shift_id',
                            'user_id',
                            'check_in_time',
                            'check_in_time_rounded',
                            'check_out_time',
                            'check_out_time_rounded',
                            'work_minutes',
                            'work_minutes_normal',
                            'work_minutes_night',
                            'work_minutes_overtime',
                            'work_minutes_night_overtime',
                        ]
                    );
                }
            }

            $count += $chunk->count();
            $this->info("Fixed {$count} of {$total}");
        }
    }
}
