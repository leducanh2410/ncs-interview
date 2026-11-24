<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_paper_ocr_results', function (Blueprint $table) {
            // Set when a source image is modified after the fact (e.g. rotation fixed),
            // so the stale result is never served as a cache hit again.
            $table->timestamp('invalidated_at')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_paper_ocr_results', function (Blueprint $table) {
            $table->dropColumn('invalidated_at');
        });
    }
};
