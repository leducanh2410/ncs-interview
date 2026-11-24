<?php

use App\Models\UserPaper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_paper_items', function (Blueprint $table) {
            $table->dateTime('approved_at')->nullable()->after('file_path');
        });

        $userPapers = UserPaper::query()->whereNotNull('approved_at')->get();
        foreach ($userPapers as $userPaper) {
            $userPaper->items()->update(['approved_at' => $userPaper->approved_at]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_paper_items', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
