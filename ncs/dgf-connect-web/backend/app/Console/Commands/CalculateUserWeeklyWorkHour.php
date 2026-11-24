<?php

namespace App\Console\Commands;

use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\SystemConfig;
use App\Models\UserConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CalculateUserWeeklyWorkHour extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cal:weekly-work-hour {userId} {shiftId} {--dry-run}';

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
        $userId = $this->argument('userId');
        $shift = Shift::query()->findOrFail($this->argument('shiftId'));
        $shiftDate = Carbon::parse($shift->date);

        $errors = [];
        $rangeStart = $shiftDate->clone()->subDays(6)->startOfDay();
        $rangeEnd = $shiftDate->clone()->addDays(6)->endOfDay();

        $historyWorkingHours = WorkHelper::getWorkHours([$userId], $rangeStart, $rangeEnd, $shift->id);
        $systemConfig = SystemConfig::query()->first();
        $configs = UserConfig::query()
            ->whereIn('user_id', [$userId])
            ->get()
            ->keyBy('user_id');
        $workHoursByDate = $historyWorkingHours[$userId] ?? [];
        $config = $configs->get($userId);
        $validationError = WorkHelper::validateShiftRegister(
            $shift,
            $rangeStart,
            $workHoursByDate,
            $systemConfig,
            $config
        );
        if ($validationError) {
            $errors[$userId] = $validationError;
        }

        $this->info(json_encode($errors));
    }
}
