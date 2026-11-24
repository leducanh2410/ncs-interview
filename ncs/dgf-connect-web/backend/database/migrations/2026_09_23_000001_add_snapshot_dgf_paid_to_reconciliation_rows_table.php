<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table) {
            $table->json('snapshot_dgf_paid')->nullable()->after('snapshot_break_column');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_rows', function (Blueprint $table) {
            $table->dropColumn('snapshot_dgf_paid');
        });
    }
};
