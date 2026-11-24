<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->string('title_ne')->nullable()->after('title_ja');
            $table->string('title_si')->nullable()->after('title_ne');
            $table->text('description_ne')->nullable()->after('description_ja');
            $table->text('description_si')->nullable()->after('description_ne');
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropColumn(['title_ne', 'title_si', 'description_ne', 'description_si']);
        });
    }
};
