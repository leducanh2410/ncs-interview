<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_paper_items', function (Blueprint $table) {
            $table->string('folder', 10)->nullable()->after('file_path');
        });

        // Backfill rejected items in batches
        DB::table('user_paper_items')
            ->whereNotNull('rejected_at')
            ->chunkById(1000, function ($items) {
                DB::table('user_paper_items')
                    ->whereIn('id', $items->pluck('id'))
                    ->update(['folder' => 'rejected']);
            });

        // For approved items, determine folder by grouping on approved_at:
        // - Items with the latest approved_at per (user_paper_id, type) → 'current'
        // - Items with older approved_at → 'old'
        // This handles multiple files approved together (e.g., passport page 1 & 2)
        $batchSize = 1000;

        // Get unique user_paper_ids to batch process
        $userPaperIds = DB::table('user_paper_items')
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->distinct()
            ->pluck('user_paper_id');

        foreach ($userPaperIds->chunk($batchSize) as $chunk) {
            // For this batch of user_paper_ids, find latest approved_at per type
            $latestPerType = DB::table('user_paper_items')
                ->select('user_paper_id', 'type', DB::raw('MAX(approved_at) as latest_approved_at'))
                ->whereIn('user_paper_id', $chunk)
                ->whereNotNull('approved_at')
                ->whereNull('rejected_at')
                ->groupBy('user_paper_id', 'type')
                ->get();

            // Collect current IDs for this batch
            $currentIds = [];
            foreach ($latestPerType as $row) {
                $ids = DB::table('user_paper_items')
                    ->where('user_paper_id', $row->user_paper_id)
                    ->where('type', $row->type)
                    ->where('approved_at', $row->latest_approved_at)
                    ->pluck('id');
                $currentIds = array_merge($currentIds, $ids->toArray());
            }

            // Update current items
            if (!empty($currentIds)) {
                DB::table('user_paper_items')
                    ->whereIn('id', $currentIds)
                    ->update(['folder' => 'current']);
            }

            // Update old items for this batch (approved but not current)
            DB::table('user_paper_items')
                ->whereIn('user_paper_id', $chunk)
                ->whereNotNull('approved_at')
                ->whereNull('rejected_at')
                ->whereNull('folder')
                ->update(['folder' => 'old']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_paper_items', function (Blueprint $table) {
            $table->dropColumn('folder');
        });
    }
};
