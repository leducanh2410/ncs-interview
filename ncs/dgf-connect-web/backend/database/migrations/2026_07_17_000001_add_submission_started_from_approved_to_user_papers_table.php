<?php

use App\Enums\UserPaperStatus;
use App\Enums\UserPaperType;
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
        Schema::table('user_papers', function (Blueprint $table) {
            $table->boolean('submission_started_from_approved')->default(false)->after('rejected_note');
        });

        // An active review without the legacy required document set could only
        // have entered review through the approved-user renewal shortcut. Keep
        // that baseline approval when this migration lands mid-review.
        $requiredTypes = UserPaperType::getRequiredTypes();
        $papersWithRequiredHistory = DB::table('user_paper_items')
            ->select('user_paper_id')
            ->whereIn('type', $requiredTypes)
            ->groupBy('user_paper_id')
            ->havingRaw('COUNT(DISTINCT type) = ?', [count($requiredTypes)]);

        DB::table('user_papers')
            ->where(function ($query) {
                $query
                    ->whereIn('user_id', function ($userQuery) {
                        $userQuery
                            ->select('id')
                            ->from('users')
                            ->whereIn('paper_status', [
                                UserPaperStatus::WaitForApproval->value,
                                UserPaperStatus::Rejected->value,
                            ]);
                    })
                    ->orWhere(function ($approvedQuery) {
                        $approvedQuery
                            ->whereIn('user_id', function ($userQuery) {
                                $userQuery
                                    ->select('id')
                                    ->from('users')
                                    ->where('paper_status', UserPaperStatus::Approved->value);
                            })
                            ->whereExists(function ($stepQuery) {
                                $stepQuery
                                    ->selectRaw('1')
                                    ->from('user_paper_steps')
                                    ->whereColumn('user_paper_steps.user_paper_id', 'user_papers.id');
                            });
                    });
            })
            ->whereNotIn('id', $papersWithRequiredHistory)
            ->update(['submission_started_from_approved' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_papers', function (Blueprint $table) {
            $table->dropColumn('submission_started_from_approved');
        });
    }
};
