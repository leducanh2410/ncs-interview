<?php

namespace Tests\Unit\Models;

use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HasTranslatableContentTest extends TestCase
{
    use RefreshDatabase;

    public function testTranslationFallsBackToEnglishWhenLocaleMissing(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['en' => 'English title', 'vi' => 'Vietnamese title']);

        $this->assertEquals('Vietnamese title', $exam->translation('vi'));
        $this->assertEquals('English title', $exam->translation('ja'));
    }

    public function testTranslationFallsBackToVietnameseWhenFallbackLocaleAlsoMissing(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['ja' => 'Japanese title', 'vi' => 'Vietnamese title']);

        $this->assertEquals('Vietnamese title', $exam->translation('ne'));
    }

    public function testTranslationReturnsNullWhenNoTranslationExistsAtAll(): void
    {
        $exam = $this->makeExam();

        $this->assertNull($exam->translation('vi'));
    }

    public function testTranslationsMapReturnsEveryLanguageWithNullForMissingOnes(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['vi' => 'VI']);

        $map = $exam->translationsMap();

        $this->assertEquals('VI', $map['vi']);
        $this->assertNull($map['en']);
        $this->assertNull($map['ja']);
        $this->assertNull($map['ne']);
        $this->assertNull($map['si']);
    }

    public function testSyncTranslationsUpsertsExistingLanguage(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['vi' => 'First']);
        $exam->syncTranslations(['vi' => 'Second']);

        $this->assertEquals('Second', $exam->translation('vi'));
        $this->assertEquals(1, $exam->translations()->where('lang', 'vi')->count());
    }

    public function testDeletingExamCleansUpItsTranslations(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['vi' => 'VI']);

        $exam->delete();

        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
        ]);
    }

    /**
     * `exam_translations` originally assumed one translatable field per
     * model. A second field (external_url) for the same Exam/lang must not
     * collide with or leak into `title`'s value.
     */
    public function testFieldParamKeepsTitleAndExternalUrlIsolated(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['en' => 'English title']);
        $exam->syncTranslations(['en' => 'https://example.com/form'], 'external_url');

        $this->assertEquals('English title', $exam->translation('en'));
        $this->assertEquals('https://example.com/form', $exam->translation('en', 'external_url'));
        $this->assertEquals('English title', $exam->translationsMap()['en']);
        $this->assertEquals('https://example.com/form', $exam->translationsMap('external_url')['en']);
    }

    /**
     * The fallback chain (locale -> en -> vi) must also apply per field, not
     * just per model - a missing external_url for one locale should fall
     * back to English same as title does.
     */
    public function testTranslationFallbackChainAppliesPerField(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['en' => 'https://example.com/en-form'], 'external_url');

        $this->assertEquals('https://example.com/en-form', $exam->translation('ja', 'external_url'));
    }

    /**
     * replaceTranslations() is what CreateExamAction/UpdateExamAction use
     * instead of syncTranslations() so that unchecking a language on save
     * removes its stale translation - otherwise a user on that locale would
     * still see the old text/link since translation() matches the exact
     * locale before ever falling back to English.
     */
    public function testReplaceTranslationsPrunesLanguagesNotInAllowedList(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['en' => 'English title', 'ja' => 'Japanese title']);

        $exam->replaceTranslations(['en' => 'English title'], ['en']);

        $this->assertEquals('English title', $exam->translation('en'));
        $this->assertNull($exam->translations()->where('lang', 'ja')->first());
    }

    /**
     * Pruning must be scoped to the field being replaced - replacing
     * `external_url`'s languages must not touch `title`'s rows for the same
     * exam/lang.
     */
    public function testReplaceTranslationsOnlyPrunesTheGivenField(): void
    {
        $exam = $this->makeExam();
        $exam->syncTranslations(['en' => 'English title', 'ja' => 'Japanese title']);
        $exam->syncTranslations(['en' => 'https://example.com/en', 'ja' => 'https://example.com/ja'], 'external_url');

        $exam->replaceTranslations(['en' => 'https://example.com/en'], ['en'], 'external_url');

        $this->assertEquals('Japanese title', $exam->translation('ja'));
        $this->assertFalse($exam->translations()->where('lang', 'ja')->where('field', 'external_url')->exists());
    }

    private function makeExam(): Exam
    {
        return Exam::create(['is_required' => false, 'retry_limit' => 0, 'min_score' => 1, 'time_limit' => 30]);
    }
}
