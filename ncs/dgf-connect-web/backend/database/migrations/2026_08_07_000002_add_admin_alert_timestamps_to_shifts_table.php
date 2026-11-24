<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dateTime('unconfirmed_alerted_at')->nullable();
            $table->dateTime('missing_check_in_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('unconfirmed_alerted_at');
            $table->dropColumn('missing_check_in_alerted_at');
        });
    }
};
