<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->string('lang', 5)->nullable()->after('status');
            $table->unsignedInteger('shuffle_seed')->nullable()->after('lang');
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropColumn(['lang', 'shuffle_seed']);
        });
    }
};
