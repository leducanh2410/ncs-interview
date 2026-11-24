<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDuplicateCheckIns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:duplicate-check-ins {--dry-run}';

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
        $shiftUserIds = CheckIn::query()
            ->select('shift_user_id')
            ->groupBy('shift_user_id')
            ->havingRaw('count(*) > 1')
            ->pluck('shift_user_id')
            ->toArray();

        if (empty($shiftUserIds)) {
            $this->info('No duplicate check-ins found.');

            return;
        }

        $this->info('Fixing for following shift user ids: ' . implode(',', $shiftUserIds));

        DB::transaction(function () use ($shiftUserIds) {
            $checkIns = CheckIn::query()
                ->whereIn('shift_user_id', $shiftUserIds)
                ->orderBy('id')
                ->get()
                ->groupBy('shift_user_id');

            $this->withProgressBar($shiftUserIds, function ($shiftUserId) use ($checkIns) {
                $userCheckIns = $checkIns->get($shiftUserId);
                $userId = $userCheckIns->first()->user_id;
                $this->info("Fixing for user {$userId}, shift user id: {$shiftUserId}");
                $this->info('Check in IDs: ' . $userCheckIns->pluck('id')->join(', '));
                $this->info('Delete: ' . $userCheckIns->last()->id);

                if (!$this->option('dry-run')) {
                    CheckIn::query()
                        ->where('id', $userCheckIns->last()->id)
                        ->delete();
                }
            });
        });
    }
}
