<?php

namespace Tests\Unit\Migrations;

use App\Models\Exam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DropSingleLanguageColumnsFromExamTablesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A full rollback batch runs this migration's down() first (recreating
     * the nullable column), then 000002's down() (which deletes every
     * exam_translations row). Without a backfill here, that sequence would
     * leave every exam title NULL instead of the pre-multilingual value.
     */
    public function testDownBackfillsFallbackLocaleBeforeTranslationsAreDeletedByEarlierMigration(): void
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations([
            'vi' => 'Tieu de VI',
            'en' => 'EN title',
            'ja' => 'JA title',
            'ne' => 'NE title',
            'si' => 'SI title',
        ]);

        $dropColumnsMigration = $this->dropColumnsMigration();
        $dropColumnsMigration->down();

        $this->assertEquals('EN title', DB::table('exams')->where('id', $exam->id)->value('title'));

        $this->migrateToTranslationsMigration()->down();

        $this->assertEquals(
            'EN title',
            DB::table('exams')->where('id', $exam->id)->value('title'),
            'title must survive a full rollback batch, not just this single migration'
        );

        // Restore schema back to the state other tests expect after migrate.
        $this->migrateToTranslationsMigration()->up();
        $dropColumnsMigration->up();
    }

    private function dropColumnsMigration(): Migration
    {
        return require database_path('migrations/2026_07_21_000003_drop_single_language_columns_from_exam_tables.php');
    }

    private function migrateToTranslationsMigration(): Migration
    {
        return require database_path('migrations/2026_07_21_000002_migrate_exam_content_to_translations.php');
    }
}
