<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const DEFAULT_FIELD_BY_TYPE = [
        'App\\Models\\Exam' => 'title',
        'App\\Models\\Question' => 'content',
        'App\\Models\\QuestionOption' => 'content',
    ];

    /**
     * `exam_translations` originally assumed exactly one translatable field
     * per model (title for Exam, content for Question/QuestionOption). To let
     * Exam also translate `external_url` (a second field), rows need a
     * `field` discriminator - otherwise a title row and an external_url row
     * for the same exam+lang would collide on the old
     * (translatable_type, translatable_id, lang) unique key.
     *
     * Left nullable at the DB level (unlike the unique index change, which
     * does apply unconditionally): migration 2026_07_21_000002 - already
     * shipped and immutable - inserts rows without a `field` value, and its
     * `up()` is replayed as-is by a rollback test. HasTranslatableContent
     * always writes an explicit field through the app, so this only matters
     * for that kind of raw/legacy insert.
     */
    public function up(): void
    {
        Schema::table('exam_translations', function (Blueprint $table) {
            $table->string('field')->nullable()->after('lang');
        });

        foreach (self::DEFAULT_FIELD_BY_TYPE as $type => $field) {
            DB::table('exam_translations')
                ->where('translatable_type', $type)
                ->update(['field' => $field]);
        }

        Schema::table('exam_translations', function (Blueprint $table) {
            $table->dropUnique('exam_translations_translatable_lang_unique');
            $table->unique(
                ['translatable_type', 'translatable_id', 'lang', 'field'],
                'exam_translations_translatable_lang_field_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_translations', function (Blueprint $table) {
            $table->dropUnique('exam_translations_translatable_lang_field_unique');
            $table->unique(
                ['translatable_type', 'translatable_id', 'lang'],
                'exam_translations_translatable_lang_unique'
            );
            $table->dropColumn('field');
        });
    }
};
