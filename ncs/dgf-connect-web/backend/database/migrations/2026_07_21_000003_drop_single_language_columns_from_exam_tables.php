<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TRANSLATABLE_MAP = [
        'exams' => ['type' => 'App\\Models\\Exam', 'column' => 'title'],
        'questions' => ['type' => 'App\\Models\\Question', 'column' => 'content'],
        'question_options' => ['type' => 'App\\Models\\QuestionOption', 'column' => 'content'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('title');
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('content');
        });
        Schema::table('question_options', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Backfills the recreated column from `exam_translations` (using the
     * app's fallback locale) while that table still holds data. If this ran
     * as a bare `nullable()` restore, the very next migration to roll back
     * (000002) would delete every translation row and leave title/content
     * NULL for a full rollback batch.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('title')->nullable();
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->text('content')->nullable();
        });
        Schema::table('question_options', function (Blueprint $table) {
            $table->text('content')->nullable();
        });

        $this->backfillFromTranslations();
    }

    private function backfillFromTranslations(): void
    {
        $fallbackLocale = config('app.fallback_locale', 'en');

        foreach (self::TRANSLATABLE_MAP as $table => $config) {
            DB::table('exam_translations')
                ->where('translatable_type', $config['type'])
                ->where('lang', $fallbackLocale)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $config) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->translatable_id)
                            ->update([
                                $config['column'] => $row->value,
                            ]);
                    }
                });
        }
    }
};
