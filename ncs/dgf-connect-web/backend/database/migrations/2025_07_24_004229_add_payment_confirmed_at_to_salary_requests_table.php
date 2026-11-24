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
        Schema::table('salary_requests', function (Blueprint $table) {
            $table->dateTime('payment_confirmed_at')->nullable()->after('payment_confirm_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_requests', function (Blueprint $table) {
            $table->dropColumn('payment_confirmed_at');
        });
    }
};
