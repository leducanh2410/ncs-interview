<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExamCreateUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                PermissionName::ExamList->value,
                PermissionName::ExamCreate->value,
                PermissionName::ExamEdit->value,
                PermissionName::ExamDelete->value,
            ]
            as $permissionName
        ) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', [
                    PermissionName::ExamList->value,
                    PermissionName::ExamCreate->value,
                    PermissionName::ExamEdit->value,
                    PermissionName::ExamDelete->value,
                ])
                ->pluck('id')
                ->all()
        );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
    }

    public function testAdminCanCreateExamWithFutureStartAt(): void
    {
        $startAt = now()->addDay()->format('Y-m-d H:i:s');
        $endAt = now()->addDays(7)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Scheduled Exam'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 1,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);
        $this->assertExamTranslation($response->json('data.id'), 'title', 'vi', 'Scheduled Exam');
    }

    public function testAdminCanCreateRequiredExamWithEndAt(): void
    {
        $startAt = now()->addDay()->format('Y-m-d H:i:s');
        $endAt = now()->addDays(7)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Required Schedule With Deadline'),
            'languages' => Locale::values(),
            'is_required' => true,
            'retry_limit' => 1,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'is_required' => true,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);
        $this->assertExamTranslation($response->json('data.id'), 'title', 'vi', 'Required Schedule With Deadline');
    }

    public function testAdminCanCreateRequiredExamWithoutEndAt(): void
    {
        $startAt = now()->addDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Required Schedule'),
            'languages' => Locale::values(),
            'is_required' => true,
            'retry_limit' => 1,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $startAt,
            'end_at' => null,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'start_at' => $startAt,
            'end_at' => null,
        ]);
        $this->assertExamTranslation($response->json('data.id'), 'title', 'vi', 'Required Schedule');
    }

    /**
     * `retry_limit` is not `required` for assessment exams (only
     * `min_score`/`time_limit` are), so a payload that omits it entirely is
     * valid and reaches the service with no `retry_limit` key at all. The
     * service must default it rather than index into a missing array key
     * (which would 500 instead of failing gracefully).
     */
    public function testCreateWithoutRetryLimitDefaultsToZero(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('No retry limit'),
            'languages' => Locale::values(),
            'is_required' => false,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'id' => $response->json('data.id'),
            'retry_limit' => 0,
        ]);
    }

    public function testCreateRejectsStartAtAfterEndAt(): void
    {
        $startAt = now()->addDays(7)->format('Y-m-d H:i:s');
        $endAt = now()->addDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Bad Schedule'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $this->assertHasValidationErrorOn($response, 'start_at');
    }

    public function testCreateAcceptsStartAtInThePast(): void
    {
        $startAt = now()->subDay()->format('Y-m-d H:i:s');
        $endAt = now()->addDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Already Active'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
    }

    public function testCreateRejectsLanguagesWithoutEnglish(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => ['vi' => 'No English'],
            'languages' => ['vi'],
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['vi']),
        ]);

        $this->assertHasValidationErrorOn($response, 'languages');
    }

    public function testCreateWithEnglishOnlySucceedsAndDoesNotRequireOtherLanguages(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => ['en' => 'English only exam'],
            'languages' => ['en'],
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en']),
        ]);

        $response->assertStatus(200);
        $examId = $response->json('data.id');
        $this->assertEquals(['en'], Exam::find($examId)->languages);
        $this->assertExamTranslation($examId, 'title', 'en', 'English only exam');
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $examId,
            'lang' => 'vi',
            'field' => 'title',
        ]);
    }

    public function testCreateRejectsMissingContentForASelectedLanguage(): void
    {
        $title = ['en' => 'Missing Japanese', 'ja' => 'Japanese title'];
        unset($title['ja']);

        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $title,
            'languages' => ['en', 'ja'],
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en', 'ja']),
        ]);

        $this->assertHasValidationErrorOn($response, 'title.ja');
    }

    /**
     * A language present in the payload but not in the `languages` selection
     * is silently ignored, not rejected and not persisted - the admin form
     * only sends fields for enabled languages, but a client could still send
     * stale/extra keys left over from unchecking a language tab.
     */
    public function testCreateIgnoresContentForALanguageNotSelected(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => ['en' => 'Selected', 'ja' => 'Not selected'],
            'languages' => ['en'],
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en']),
        ]);

        $response->assertStatus(200);
        $examId = $response->json('data.id');
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $examId,
            'lang' => 'ja',
            'field' => 'title',
        ]);
    }

    public function testUpdateRejectsChangingLockedStartAt(): void
    {
        $exam = $this->makeExam('Existing', [
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
        $question = $exam->questions()->create([]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $this->createOptions($question, 'A', 'B');

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('Edited'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload([], $question->id),
        ]);

        $this->assertHasValidationErrorOn($response, 'start_at');
    }

    public function testUpdateAcceptsSameLockedStartAt(): void
    {
        $startAt = now()->subDay()->format('Y-m-d H:i:s');
        $exam = $this->makeExam('Existing', [
            'start_at' => $startAt,
            'end_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
        $question = $exam->questions()->create([]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $this->createOptions($question, 'A', 'B');

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('Edited'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $startAt,
            'end_at' => now()->addDays(14)->format('Y-m-d H:i:s'),
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload([], $question->id),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'id' => $exam->id,
            'start_at' => $startAt,
        ]);
        $this->assertExamTranslation($exam->id, 'title', 'vi', 'Edited');
    }

    /**
     * Every exam created before the `type` column existed (or via any
     * client that omits it) has a raw `type` of `null`, which the model
     * accessor maps to `assessment`. A normal update payload that sends
     * `"type": null` (or omits the key) doesn't change the type at all -
     * that must not be misread as "changing away from assessment" and
     * rejected once the exam has real exam_results (the actual state of
     * every exam running in production).
     */
    public function testUpdateWithNullTypeOnLegacyNullTypeExamWithResultsSucceeds(): void
    {
        $exam = $this->makeExam('Legacy exam', ['type' => null]);
        $question = $exam->questions()->create([]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $this->createOptions($question, 'A', 'B');

        $employee = User::factory()->create();
        ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('Edited legacy exam'),
            'languages' => Locale::values(),
            'type' => null,
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->existingQuestionsPayload($question),
        ]);

        $response->assertStatus(200);
        $this->assertExamTranslation($exam->id, 'title', 'vi', 'Edited legacy exam');
    }

    public function testUpdateAllowsChangingFutureStartAt(): void
    {
        $exam = $this->makeExam('Future', [
            'start_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'end_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
        ]);
        $question = $exam->questions()->create([]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $this->createOptions($question, 'A', 'B');

        $newStart = now()->addDays(3)->format('Y-m-d H:i:s');
        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('Future'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => $newStart,
            'end_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload([], $question->id),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'id' => $exam->id,
            'start_at' => $newStart,
        ]);
    }

    public function testCreateRejectsMinScoreExceedingQuestionCount(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Too High Passing Score'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 2,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $this->assertHasValidationErrorOn($response, 'min_score');
    }

    public function testCreateAcceptsMinScoreEqualToQuestionCount(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Exact Passing Score'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
    }

    /**
     * question_options cascade-delete at the DB level when their parent
     * question is removed, which bypasses Eloquent's `deleting` event -
     * UpdateExamAction must clean up their exam_translations rows
     * explicitly, otherwise they'd be orphaned.
     */
    public function testUpdateRemovingQuestionCleansUpItsAndItsOptionsTranslations(): void
    {
        $exam = $this->makeExam('Existing');
        $keptQuestion = $exam->questions()->create([]);
        $keptQuestion->syncTranslations($this->langPayload('Kept question'));
        $this->createOptions($keptQuestion, 'A', 'B');

        $removedQuestion = $exam->questions()->create([]);
        $removedQuestion->syncTranslations($this->langPayload('Removed question'));
        $removedOption = $removedQuestion->options()->create(['is_correct' => true]);
        $removedOption->syncTranslations($this->langPayload('Removed option'));
        $removedQuestionId = $removedQuestion->id;
        $removedOptionId = $removedOption->id;

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('Existing'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload([], $keptQuestion->id),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('questions', ['id' => $removedQuestionId]);
        $this->assertDatabaseMissing('question_options', ['id' => $removedOptionId]);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Question::class,
            'translatable_id' => $removedQuestionId,
        ]);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => QuestionOption::class,
            'translatable_id' => $removedOptionId,
        ]);
    }

    /**
     * Deleting an exam cascades to questions/question_options at the DB
     * level (bypassing Eloquent's `deleting` event), so DeleteExamAction
     * must clean up every level's exam_translations rows explicitly.
     */
    public function testDeleteExamCleansUpAllNestedTranslations(): void
    {
        $exam = $this->makeExam('To delete');
        $question = $exam->questions()->create([]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $this->createOptions($question, 'A', 'B');
        $examId = $exam->id;
        $questionId = $question->id;
        $optionIds = $question->options()->pluck('id')->all();

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/admin/exams/{$examId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('exams', ['id' => $examId]);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $examId,
        ]);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Question::class,
            'translatable_id' => $questionId,
        ]);
        foreach ($optionIds as $optionId) {
            $this->assertDatabaseMissing('exam_translations', [
                'translatable_type' => QuestionOption::class,
                'translatable_id' => $optionId,
            ]);
        }
    }

    /**
     * Lifecycle/accumulated-history scenario reached through the real
     * create -> update endpoints (Test Design Rules): an exam is created
     * with 3 languages, then an admin edit narrows it down to English only.
     * The dropped languages' title/question/option translations must be
     * gone afterward, not merely un-required - otherwise a user on `ja`
     * would still see the stale title/content since translation() matches
     * the exact locale before ever falling back to English.
     */
    public function testUpdateNarrowingLanguagesRemovesDroppedLanguagesEverywhere(): void
    {
        $createResponse = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => ['en' => 'EN title', 'ja' => 'JA title', 'vi' => 'VI title'],
            'languages' => ['en', 'ja', 'vi'],
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en', 'ja', 'vi']),
        ]);
        $createResponse->assertStatus(200);
        $examId = $createResponse->json('data.id');
        $questionId = $createResponse->json('data.questions.0.id');
        $optionId = $createResponse->json('data.questions.0.options.0.id');

        $updateResponse = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$examId}", [
            'title' => ['en' => 'EN title'],
            'languages' => ['en'],
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en'], $questionId, $optionId),
        ]);

        $updateResponse->assertStatus(200);
        $this->assertEquals(['en'], Exam::find($examId)->languages);
        foreach (['ja', 'vi'] as $droppedLang) {
            $this->assertDatabaseMissing('exam_translations', [
                'translatable_type' => Exam::class,
                'translatable_id' => $examId,
                'lang' => $droppedLang,
                'field' => 'title',
            ]);
            $this->assertDatabaseMissing('exam_translations', [
                'translatable_type' => Question::class,
                'translatable_id' => $questionId,
                'lang' => $droppedLang,
            ]);
            $this->assertDatabaseMissing('exam_translations', [
                'translatable_type' => QuestionOption::class,
                'translatable_id' => $optionId,
                'lang' => $droppedLang,
            ]);
        }

        $showResponse = $this->actingAs($this->admin, 'api')->getJson("/admin/exams/{$examId}");
        $showResponse->assertStatus(200);
        $this->assertNull($showResponse->json('data.title.ja'));
        $this->assertNull($showResponse->json('data.title.vi'));
        $this->assertEquals('EN title', $showResponse->json('data.title.en'));
    }

    /**
     * Build a value for every supported language (see `Locale`), using the
     * same text everywhere - tests don't care that the "translations" match.
     */
    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }

    private function makeExam(string $title, array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'is_required' => false,
                    'retry_limit' => 0,
                    'min_score' => 1,
                    'time_limit' => 30,
                ],
                $overrides
            )
        );
        $exam->syncTranslations($this->langPayload($title));

        return $exam;
    }

    private function createOptions($question, string $correctText, string $wrongText): void
    {
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations($this->langPayload($correctText));
        $wrong = $question->options()->create(['is_correct' => false]);
        $wrong->syncTranslations($this->langPayload($wrongText));
    }

    private function assertExamTranslation(int $translatableId, string $field, string $lang, string $expected): void
    {
        $table = $field === 'title' || $field === 'external_url' ? Exam::class : null;
        $this->assertDatabaseHas('exam_translations', [
            'translatable_type' => $table,
            'translatable_id' => $translatableId,
            'lang' => $lang,
            'field' => $field,
            'value' => $expected,
        ]);
    }

    /**
     * Build the questions payload. Updates send `id` to identify which existing
     * question rows are being modified; `null` (or missing) means new question.
     *
     * @param string[] $languages languages to fill (defaults to all 5, tests care
     *                            about a subset pass this explicitly)
     */
    private function questionsPayload(
        array $languages = [],
        ?int $existingQuestionId = null,
        ?int $existingOptionId = null
    ): array {
        $languages = $languages ?: Locale::values();

        return [
            [
                'id' => $existingQuestionId,
                'content' => array_fill_keys($languages, 'Q1?'),
                'options' => [
                    ['id' => $existingOptionId, 'content' => array_fill_keys($languages, 'A'), 'is_correct' => true],
                    ['id' => null, 'content' => array_fill_keys($languages, 'B'), 'is_correct' => false],
                ],
            ],
        ];
    }

    private function existingQuestionsPayload(Question $question, array $contentOverrides = []): array
    {
        return [
            [
                'id' => $question->id,
                'content' => $contentOverrides['question'] ?? $this->langPayload('Q1?'),
                'options' => $question
                    ->options()
                    ->get()
                    ->map(
                        fn(QuestionOption $option) => [
                            'id' => $option->id,
                            'content' => $contentOverrides[$option->id] ?? $this->langPayload('Option ' . $option->id),
                            'is_correct' => $option->is_correct,
                        ]
                    )
                    ->all(),
            ],
        ];
    }

    private function assertHasValidationErrorOn(TestResponse $response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY');
        $errors = $response->json('data') ?? [];
        $this->assertArrayHasKey(
            $field,
            $errors,
            "Expected validation error on '{$field}', got: " . json_encode($errors)
        );
    }
}
