<?php

namespace Tests\Unit\Migrations;

use App\Models\Exam;
use App\Models\Question;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddFieldColumnToExamTranslationsTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simulates the pre-migration state (no `field` column, rows shared one
     * implicit field per model type) by rolling back first, inserting rows
     * the old way, then reapplying to exercise the real backfill: Exam rows
     * become `field=title`, Question/QuestionOption rows become
     * `field=content`.
     */
    public function testUpBackfillsFieldFromTranslatableTypeAndAllowsTwoFieldsPerModel(): void
    {
        $migration = $this->migration();
        $migration->down();

        $titleRowId = DB::table('exam_translations')->insertGetId([
            'translatable_type' => Exam::class,
            'translatable_id' => 1,
            'lang' => 'en',
            'value' => 'Some title',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contentRowId = DB::table('exam_translations')->insertGetId([
            'translatable_type' => Question::class,
            'translatable_id' => 1,
            'lang' => 'en',
            'value' => 'Some content',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertEquals('title', DB::table('exam_translations')->where('id', $titleRowId)->value('field'));
        $this->assertEquals('content', DB::table('exam_translations')->where('id', $contentRowId)->value('field'));

        // The whole point of this migration: an Exam can now have a second
        // translatable field (e.g. external_url) for the same lang without
        // colliding with `title` on the old (type, id, lang) unique key.
        DB::table('exam_translations')->insert([
            'translatable_type' => Exam::class,
            'translatable_id' => 1,
            'lang' => 'en',
            'field' => 'external_url',
            'value' => 'https://example.com/form',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => 1,
            'lang' => 'en',
            'field' => 'external_url',
        ]);
    }

    public function testDownDropsFieldColumn(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('exam_translations', 'field'));

        // Restore schema back to the state other tests expect after migrate.
        $migration->up();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_07_30_000001_add_field_column_to_exam_translations_table.php');
    }
}
