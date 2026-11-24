<?php

namespace Tests\Unit\Services\Admin;

use App\Enums\ExamType;
use App\Enums\Locale;
use App\Models\Exam;
use App\Services\Admin\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Direct unit coverage of the pure helper logic still living on ExamService
 * (`resolveTypeDependentFields`/`resolveExternalUrlTranslations`), used by
 * both CreateExamAction and UpdateExamAction. The orchestration around them
 * (transactions, translations, question/option sync) is covered by
 * CreateExamActionTest/UpdateExamActionTest and the Feature-level exam tests.
 */
class ExamServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExamService::class);
    }

    /**
     * `min_score`/`time_limit` are only required by CreateUpdateExamRequest
     * for non-external types, so a payload reaching this branch without them
     * is possible for any caller that skips that request. Reading the keys
     * blindly would raise "Undefined array key" and a 500; this must fail as
     * a controlled 422 instead.
     */
    public function testResolveTypeDependentFieldsThrowsWhenAssessmentPayloadMissingScoringFields(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->resolveTypeDependentFields(ExamType::Assessment->value, []);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());

            throw $e;
        }
    }

    public function testResolveTypeDependentFieldsAppliesExternalFormPrepDefaults(): void
    {
        $fields = $this->service->resolveTypeDependentFields(ExamType::ExternalFormPrep->value, []);

        $this->assertEquals(
            [
                'is_required' => true,
                'is_recurring' => false,
                'show_result_immediately' => false,
                'retry_limit' => 0,
                'min_score' => 0,
                'time_limit' => config('exam.default_external_time_limit'),
            ],
            $fields
        );
    }

    public function testResolveExternalUrlTranslationsReturnsEmptyForNonExternalType(): void
    {
        $result = $this->service->resolveExternalUrlTranslations(
            ExamType::Assessment->value,
            ['external_url' => ['en' => 'https://example.com']],
            Locale::values()
        );

        $this->assertSame([], $result);
    }

    /**
     * A payload that omits `external_url` entirely (same "doesn't resend
     * every field" case as `type`) must preserve the exam's current
     * per-language URLs on update rather than wiping them.
     */
    public function testResolveExternalUrlTranslationsPreservesExistingWhenKeyOmitted(): void
    {
        $exam = Exam::create([
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 120,
        ]);
        $exam->syncTranslations(['en' => 'https://example.com/en'], 'external_url');

        $result = $this->service->resolveExternalUrlTranslations(ExamType::ExternalFormPrep->value, [], ['en'], $exam);

        $this->assertSame(['en' => 'https://example.com/en'], $result);
    }

    /**
     * "An `external_form_prep` exam always has a URL for every selected
     * language" is enforced here, not only in CreateUpdateExamRequest - a
     * caller that doesn't go through that request must not be able to
     * persist an external exam missing a link for one of its languages.
     */
    public function testResolveExternalUrlTranslationsThrowsWhenSelectedLanguageMissingUrl(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->service->resolveExternalUrlTranslations(
                ExamType::ExternalFormPrep->value,
                ['external_url' => ['en' => 'https://example.com/en']],
                ['en', 'ja']
            );
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('external_url.ja', $e->errors());

            throw $e;
        }
    }
}
