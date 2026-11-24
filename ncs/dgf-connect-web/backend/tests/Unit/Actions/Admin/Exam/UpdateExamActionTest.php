<?php

namespace Tests\Unit\Actions\Admin\Exam;

use App\Actions\Admin\Exam\UpdateExamAction;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdateExamActionTest extends TestCase
{
    use RefreshDatabase;

    private UpdateExamAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(UpdateExamAction::class);
    }

    /**
     * Direct unit coverage of the guard: CreateUpdateExamRequest's check is
     * bypassed entirely when calling the Action directly (e.g. from a queued
     * job or another internal caller), so the guard here must independently
     * reject a type change on an exam that already has exam_results.
     */
    public function testUpdateThrowsWhenTypeChangesAndResultsExist(): void
    {
        $exam = $this->makeExam(['type' => ExamType::ExternalFormPrep->value, 'min_score' => 0]);
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'status' => 'pending_external',
        ]);

        $this->expectException(ValidationException::class);

        try {
            ($this->action)($exam, $this->updatePayload($exam, ['type' => ExamType::Assessment->value]));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
            $exam->refresh();
            $this->assertEquals(ExamType::ExternalFormPrep, $exam->type);

            throw $e;
        }
    }

    /**
     * The bug this guards against: a legacy exam (raw `type` column is
     * `null`, predating the column) already has exam_results, same as any
     * real exam in production. A normal update payload that doesn't touch
     * `type` at all - either by omitting the key, or a client explicitly
     * sending `"type": null` - must not be misread as "changing away from
     * assessment" just because the raw stored value (`null`) doesn't
     * string-match the accessor's resolved value (`'assessment'`).
     */
    public function testUpdateDoesNotThrowForLegacyNullTypeExamWithResultsWhenTypeUntouched(): void
    {
        $exam = $this->makeExam(['type' => null]);
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        ExamResult::create([
            'user_id' => $user->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => 'completed',
        ]);

        $payloadWithoutType = $this->updatePayload($exam);
        unset($payloadWithoutType['type']);
        $updated = ($this->action)($exam, $payloadWithoutType);
        $this->assertEquals(ExamType::Assessment, $updated->type);

        $exam->refresh();
        $updated = ($this->action)($exam, $this->updatePayload($exam, ['type' => null]));
        $this->assertEquals(ExamType::Assessment, $updated->type);
    }

    /**
     * "An `external_form_prep` exam always has a URL" is enforced where the
     * value is resolved, not only in CreateUpdateExamRequest - a caller that
     * doesn't go through that request must not be able to persist an
     * external exam whose users get no link. Switching an `assessment` exam
     * (whose `external_url` translations were never populated) to
     * `external_form_prep` without sending a URL is exactly that case: there
     * is no prior value to preserve.
     */
    public function testUpdateRejectsSwitchToExternalFormPrepWithoutAnyExternalUrl(): void
    {
        $exam = $this->makeExam(['type' => ExamType::Assessment->value]);

        $payload = $this->updatePayload($exam, ['type' => ExamType::ExternalFormPrep->value]);
        $this->assertArrayNotHasKey('external_url', $payload);

        $this->expectException(ValidationException::class);

        try {
            ($this->action)($exam, $payload);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('external_url.vi', $e->errors());
            $exam->refresh();
            $this->assertEquals(ExamType::Assessment, $exam->type);
            $this->assertNull($exam->translation('vi', 'external_url'));

            throw $e;
        }
    }

    /**
     * `min_score`/`time_limit` are only required by CreateUpdateExamRequest
     * for non-external types, so a payload reaching the assessment branch
     * without them is possible for any caller that skips that request.
     * Reading the keys blindly would raise "Undefined array key" and a 500;
     * this must fail as a controlled 422 instead.
     */
    public function testUpdateRejectsAssessmentPayloadMissingScoringFields(): void
    {
        $exam = $this->makeExam(['type' => ExamType::Assessment->value]);

        $payload = $this->updatePayload($exam);
        unset($payload['min_score'], $payload['time_limit']);

        $this->expectException(ValidationException::class);

        try {
            ($this->action)($exam, $payload);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());

            throw $e;
        }
    }

    /**
     * Lifecycle/accumulated-history scenario (Test Design Rules): an exam
     * that already has ja+vi title translations from a previous save gets
     * updated to select only `en`. replaceTranslations() must prune the
     * dropped languages, not just stop requiring them - otherwise a user on
     * `ja`/`vi` would still see the stale title since translation() matches
     * the exact locale before ever falling back to English.
     */
    public function testUpdateRemovingLanguagePrunesItsTranslations(): void
    {
        $exam = $this->makeExam();
        $exam->update(['languages' => ['en', 'ja', 'vi']]);
        $exam->replaceTranslations(
            ['en' => 'English title', 'ja' => 'Japanese title', 'vi' => 'Vietnamese title'],
            ['en', 'ja', 'vi']
        );

        $payload = $this->updatePayload($exam, ['languages' => ['en'], 'title' => ['en' => 'English title only']]);
        ($this->action)($exam, $payload);

        $this->assertEquals('English title only', $exam->translation('en'));
        $this->assertFalse($exam->translations()->where('lang', 'ja')->where('field', 'title')->exists());
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'lang' => 'ja',
            'field' => 'title',
        ]);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'lang' => 'vi',
            'field' => 'title',
        ]);
    }

    private function updatePayload(Exam $exam, array $overrides = []): array
    {
        $exam->loadMissing('questions.options');
        $question = $exam->questions->first();
        $option = $question->options->first();

        return array_merge(
            [
                'title' => array_fill_keys(Locale::values(), 'Updated title'),
                'languages' => Locale::values(),
                'is_required' => false,
                'retry_limit' => 0,
                'min_score' => 1,
                'time_limit' => 30,
                'start_at' => null,
                'end_at' => null,
                'documents' => [],
                'workplaces' => [],
                'questions' => [
                    [
                        'id' => $question->id,
                        'content' => array_fill_keys(Locale::values(), 'Q1?'),
                        'options' => [
                            [
                                'id' => $option->id,
                                'content' => array_fill_keys(Locale::values(), 'A'),
                                'is_correct' => true,
                            ],
                        ],
                    ],
                ],
            ],
            $overrides
        );
    }

    private function makeExam(array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'is_required' => true,
                    'retry_limit' => 0,
                    'min_score' => 1,
                    'time_limit' => 30,
                ],
                $overrides
            )
        );
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Exam'));
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(array_fill_keys(Locale::values(), 'Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations(array_fill_keys(Locale::values(), 'A'));

        return $exam;
    }
}
