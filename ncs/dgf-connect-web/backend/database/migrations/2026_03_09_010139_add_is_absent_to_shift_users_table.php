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
        Schema::table('shift_users', function (Blueprint $table) {
            $table->boolean('is_absent')->default(false)->after('is_reported');
            $table->index('is_absent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_users', function (Blueprint $table) {
            $table->dropIndex(['is_absent']);
            $table->dropColumn('is_absent');
        });
    }
};
