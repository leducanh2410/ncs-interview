<?php

use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Every exam created before this feature already has all 5 languages
     * filled in (see 2026_07_21_000002's backfill), so defaulting existing
     * rows to every language keeps them displaying exactly as before -
     * `languages` only starts narrowing down from the next admin edit/create.
     *
     * Left nullable at the DB level: Exam::$attributes provides the same
     * all-languages default for any code path that creates a row without
     * passing `languages` explicitly (every pre-existing test helper that
     * builds an Exam directly), so a NOT NULL constraint would only add a
     * second, redundant place to keep in sync.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->json('languages')->nullable()->after('type');
        });

        DB::table('exams')->update(['languages' => json_encode(Locale::values())]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('languages');
        });
    }
};
