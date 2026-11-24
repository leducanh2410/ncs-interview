<?php

namespace Tests\Unit\Migrations;

use App\Enums\Locale;
use App\Models\Exam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MoveExternalUrlToExamTranslationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simulates the pre-migration state (external_url still a plain column
     * with a legacy value shown to every locale) by rolling back first, then
     * reapplying to exercise the real up() backfill + column drop. The exam
     * has all 5 languages selected (the default, and what the previous
     * migration sets every pre-existing exam to), so the backfill must write
     * a row for every one of them - not just `en` - or
     * ExamService::resolveExternalUrlTranslations() would reject the exam's
     * very next save as missing a URL for its other selected languages.
     */
    public function testUpMovesExistingColumnValueIntoEveryLanguageTranslationThenDropsColumn(): void
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);

        $migration = $this->migration();
        $migration->down();
        DB::table('exams')
            ->where('id', $exam->id)
            ->update(['external_url' => 'https://old-link.example']);

        $migration->up();

        foreach (Locale::values() as $lang) {
            $this->assertDatabaseHas('exam_translations', [
                'translatable_type' => Exam::class,
                'translatable_id' => $exam->id,
                'lang' => $lang,
                'field' => 'external_url',
                'value' => 'https://old-link.example',
            ]);
        }
        $this->assertFalse(Schema::hasColumn('exams', 'external_url'));
    }

    /**
     * An exam whose `languages` selection was already narrowed before this
     * migration runs (e.g. a fresh row inserted between the two deploys)
     * must only get translation rows for the languages it actually has
     * selected, not every locale.
     */
    public function testUpOnlyBackfillsLanguagesTheExamHasSelected(): void
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'languages' => ['en', 'vi'],
        ]);

        $migration = $this->migration();
        $migration->down();
        DB::table('exams')
            ->where('id', $exam->id)
            ->update(['external_url' => 'https://old-link.example']);

        $migration->up();

        foreach (['en', 'vi'] as $lang) {
            $this->assertDatabaseHas('exam_translations', [
                'translatable_type' => Exam::class,
                'translatable_id' => $exam->id,
                'lang' => $lang,
                'field' => 'external_url',
                'value' => 'https://old-link.example',
            ]);
        }
        foreach (['ja', 'ne', 'si'] as $lang) {
            $this->assertDatabaseMissing('exam_translations', [
                'translatable_type' => Exam::class,
                'translatable_id' => $exam->id,
                'lang' => $lang,
                'field' => 'external_url',
            ]);
        }
    }

    public function testDownRestoresColumnFromEnglishTranslationAndRemovesTheRow(): void
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations(['en' => 'https://link.example'], 'external_url');

        $migration = $this->migration();
        $migration->down();

        $this->assertEquals('https://link.example', DB::table('exams')->where('id', $exam->id)->value('external_url'));
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'field' => 'external_url',
        ]);

        // Restore schema back to the state other tests expect after migrate.
        $migration->up();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_07_30_000003_move_external_url_to_exam_translations.php');
    }
}
