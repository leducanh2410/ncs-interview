<?php

use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * `external_url` becomes a per-language field so each language can link
     * to a different Google Form. Existing exams only ever had one URL shown
     * to every user regardless of locale, so the same value is backfilled
     * into every language the exam has selected (`exams.languages`, set by
     * the previous migration to all 5 for every pre-existing exam) - not
     * just `en`. ExamService::resolveExternalUrlTranslations() requires a
     * translation row for each of the exam's selected languages, so
     * backfilling `en` only would make every legacy external exam fail to
     * save with a 422 the moment an admin touches it post-deploy.
     */
    public function up(): void
    {
        DB::table('exams')
            ->select('id', 'external_url', 'languages')
            ->whereNotNull('external_url')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                $now = now();
                $inserts = [];
                foreach ($rows as $row) {
                    $languages = $row->languages !== null ? json_decode($row->languages, true) : Locale::values();
                    foreach ($languages as $lang) {
                        $inserts[] = [
                            'translatable_type' => 'App\\Models\\Exam',
                            'translatable_id' => $row->id,
                            'lang' => $lang,
                            'field' => 'external_url',
                            'value' => $row->external_url,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if (!empty($inserts)) {
                    DB::table('exam_translations')->insert($inserts);
                }
            });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('external_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('external_url')->nullable()->after('end_at');
        });

        DB::table('exam_translations')
            ->where('translatable_type', 'App\\Models\\Exam')
            ->where('field', 'external_url')
            ->where('lang', 'en')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('exams')
                        ->where('id', $row->translatable_id)
                        ->update(['external_url' => $row->value]);
                }
            });

        DB::table('exam_translations')
            ->where('translatable_type', 'App\\Models\\Exam')
            ->where('field', 'external_url')
            ->delete();
    }
};
