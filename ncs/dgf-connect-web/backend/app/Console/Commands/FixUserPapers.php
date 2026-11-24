<?php

namespace App\Console\Commands;

use App\Enums\UserPaperStatus;
use App\Models\UserPaperItem;
use Illuminate\Console\Command;

class FixUserPapers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:user-papers';

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
        $userPapers = UserPaperItem::query()
            ->select('user_id', 'user_paper_items.id')
            ->join('user_papers', 'user_papers.id', '=', 'user_paper_items.user_paper_id')
            ->join('users', 'users.id', '=', 'user_papers.user_id')
            ->whereNotNull('user_paper_items.approved_at')
            ->whereNotNull('user_papers.approved_at')
            ->where('users.paper_status', UserPaperStatus::WaitForApproval)
            ->whereRaw('user_paper_items.created_at > user_papers.approved_at')
            ->get();
        $userIds = $userPapers->pluck('user_id')->unique()->toArray();
        $this->info('Fixing for following users: ' . implode(',', $userIds));

        $userPaperItemIds = $userPapers->pluck('id')->toArray();
        $this->info('Fixing for following user paper items: ' . implode(',', $userPaperItemIds));
        UserPaperItem::query()
            ->whereIn('id', $userPaperItemIds)
            ->update(['approved_at' => null]);
    }
}
