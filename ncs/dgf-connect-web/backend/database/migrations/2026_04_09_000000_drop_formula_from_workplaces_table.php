<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            $table->dropColumn('formula');
        });
    }

    public function down(): void
    {
        Schema::table('workplaces', function (Blueprint $table) {
            $table->string('formula')->after('latitude');
        });
    }
};
