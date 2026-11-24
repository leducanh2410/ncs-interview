<?php

namespace Tests\Unit\Actions\Admin\Exam;

use App\Actions\Admin\Exam\CreateExamAction;
use App\Enums\ExamType;
use App\Enums\Locale;
use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateExamActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateExamAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(CreateExamAction::class);
    }

    public function testCreateOnlyPersistsSelectedLanguages(): void
    {
        $exam = ($this->action)(
            $this->payload([
                'languages' => ['en', 'ja'],
                'title' => ['en' => 'English title', 'ja' => 'Japanese title', 'vi' => 'Vietnamese title'],
            ])
        );

        $this->assertEquals(['en', 'ja'], $exam->languages);
        $this->assertDatabaseHas('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'lang' => 'en',
            'field' => 'title',
            'value' => 'English title',
        ]);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'lang' => 'vi',
            'field' => 'title',
        ]);
    }

    public function testCreateExternalFormPrepStoresDifferentLinkPerLanguage(): void
    {
        $exam = ($this->action)(
            $this->payload([
                'type' => ExamType::ExternalFormPrep->value,
                'languages' => ['en', 'ja'],
                'external_url' => ['en' => 'https://example.com/en', 'ja' => 'https://example.com/ja'],
            ])
        );

        $this->assertDatabaseHas('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'lang' => 'en',
            'field' => 'external_url',
            'value' => 'https://example.com/en',
        ]);
        $this->assertDatabaseHas('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'lang' => 'ja',
            'field' => 'external_url',
            'value' => 'https://example.com/ja',
        ]);
    }

    /**
     * Test Design Rules: the failure path of a transaction with multiple
     * writes must roll back cleanly. Here the exam row and its title
     * translations are written before resolveExternalUrlTranslations()
     * throws (missing link for the `ja` language) - the whole create must
     * leave no trace, not just fail to add the link.
     */
    public function testCreateRollsBackWholeExamWhenExternalUrlMissingForOneSelectedLanguage(): void
    {
        $countBefore = Exam::count();

        try {
            ($this->action)(
                $this->payload([
                    'type' => ExamType::ExternalFormPrep->value,
                    'languages' => ['en', 'ja'],
                    'external_url' => ['en' => 'https://example.com/en'],
                ])
            );
            $this->fail('Expected a ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('external_url.ja', $e->errors());
        }

        $this->assertEquals($countBefore, Exam::count());
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'lang' => 'en',
            'field' => 'title',
            'value' => 'English title',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(
            [
                'title' => array_fill_keys(Locale::values(), 'English title'),
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
                        'id' => null,
                        'content' => array_fill_keys(Locale::values(), 'Q1?'),
                        'options' => [
                            ['id' => null, 'content' => array_fill_keys(Locale::values(), 'A'), 'is_correct' => true],
                            [
                                'id' => null,
                                'content' => array_fill_keys(Locale::values(), 'B'),
                                'is_correct' => false,
                            ],
                        ],
                    ],
                ],
            ],
            $overrides
        );
    }
}
