<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_rest_hours')->nullable()->default(9)->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            $table->dropColumn('min_rest_hours');
        });
    }
};
