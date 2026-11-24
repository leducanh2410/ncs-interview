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
        Schema::table('workplace_positions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index()->after('name');
        });
        Schema::table('workplace_shifts', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index()->after('break_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workplace_positions', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
        Schema::table('workplace_shifts', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
