<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesRecurringShiftFlow;
use Tests\TestCase;

class RecurringExamAdminTest extends TestCase
{
    use DrivesRecurringShiftFlow;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        foreach (
            [
                PermissionName::ExamList,
                PermissionName::ExamCreate,
                PermissionName::ExamEdit,
                PermissionName::ExamResultList,
                PermissionName::ExamResultExport,
            ]
            as $name
        ) {
            $permission = Permission::firstOrCreate(['name' => $name->value], ['display_name' => $name->value]);
            $this->admin->givePermission($permission, null);
        }

        $this->workplace = Workplace::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRecurringExamIsStoredAsRequiredWithoutEndDate(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson(
                '/admin/exams',
                $this->payload(['is_required' => false, 'end_at' => now()->addDays(7)->format('Y-m-d H:i:s')])
            )
            ->assertOk()
            ->assertJsonPath('data.is_recurring', true)
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.end_at', null);

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/exams/' . $response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.is_recurring', true);
    }

    public function testRecurringExamRequiresAWorkplace(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/admin/exams', $this->payload(['workplaces' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');
    }

    public function testExternalFormExamCannotBeRecurring(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(
                '/admin/exams',
                $this->payload([
                    'type' => ExamType::ExternalFormPrep->value,
                    'external_url' => $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
                ])
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');
    }

    public function testChangingARecurringExamToExternalFormIsRejected(): void
    {
        $examId = $this->actingAs($this->admin, 'api')
            ->postJson('/admin/exams', $this->payload())
            ->assertOk()
            ->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/admin/exams/{$examId}",
                $this->payload([
                    'type' => ExamType::ExternalFormPrep->value,
                    'external_url' => $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
                ])
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');

        $this->assertTrue(Exam::query()->findOrFail($examId)->is_recurring);
    }

    public function testUpdateClearsTheEndDateAndOmittedFlagTurnsRecurringOff(): void
    {
        $examId = $this->actingAs($this->admin, 'api')
            ->postJson('/admin/exams', $this->payload())
            ->assertOk()
            ->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/admin/exams/{$examId}",
                $this->payload(['is_required' => false, 'end_at' => now()->addDays(3)->format('Y-m-d H:i:s')])
            )
            ->assertOk();

        $exam = Exam::query()->findOrFail($examId);
        $this->assertTrue($exam->is_required);
        $this->assertNull($exam->end_at);

        $payload = $this->payload(['is_required' => false]);
        unset($payload['is_recurring']);
        $this->actingAs($this->admin, 'api')
            ->putJson("/admin/exams/{$examId}", $payload)
            ->assertOk();

        $exam->refresh();
        $this->assertFalse($exam->is_recurring);
        $this->assertFalse($exam->is_required);
    }

    public function testStatisticsResultsAndExportCanBeFilteredByOccurrence(): void
    {
        Queue::fake();
        $this->travelToDate('2026-09-01 08:00:00');
        $this->setUpRecurringShiftFlow();
        $employee = $this->createRecurringMember($this->workplace);
        $exam = $this->recurringExamWithOneQuestion([$this->workplace]);

        $first = $this->approveShift($employee, $this->workplace, '2026-09-01');
        $this->takeExam($employee, $exam, correct: false);
        $this->takeExam($employee, $exam, correct: true);
        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($employee, $this->workplace, '2026-09-02', 9);
        $this->travelToDate('2026-09-11 08:00:00');
        $this->approveShift($employee, $this->workplace, '2026-09-11');
        $this->takeExam($employee, $exam, correct: true);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/exams/{$exam->id}/statistics")
            ->assertOk()
            ->assertJsonPath('data.summary.attempt_count', 3)
            ->assertJsonPath('data.available_occurrences', [1, 2]);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/exams/{$exam->id}/statistics?occurrence=2")
            ->assertOk()
            ->assertJsonPath('data.summary.attempt_count', 1)
            ->assertJsonPath('data.summary.passed_count', 1);

        $this->actingAs($this->admin, 'api')
            ->getJson('/admin/exam-results?occurrence=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.occurrence', 1)
            ->assertJsonPath('data.data.0.workplace.name', $this->workplace->name);

        $csv = $this->actingAs($this->admin, 'api')->get('/admin/exam-results/export?occurrence=2');
        $csv->assertOk();
        $rows = array_values(array_filter(preg_split('/\r?\n/', trim($csv->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertCount(2, $rows);
        $this->assertStringContainsString($this->workplace->name, $rows[1]);
    }

    public function testStatisticsOfARecurringExamAreSplitByTheAttemptWorkplace(): void
    {
        Queue::fake();
        $this->travelToDate('2026-09-01 08:00:00');
        $this->setUpRecurringShiftFlow();
        $otherWorkplace = Workplace::factory()->create();
        $employee = $this->createRecurringMember($this->workplace, $otherWorkplace);
        $exam = $this->recurringExamWithOneQuestion([$this->workplace, $otherWorkplace]);

        $this->approveShift($employee, $this->workplace, '2026-09-01');
        $this->approveShift($employee, $otherWorkplace, '2026-09-02');
        $this->takeExam($employee, $exam, correct: true);
        $this->takeExam($employee, $exam, correct: true);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/exams/{$exam->id}/statistics")
            ->assertOk()
            ->assertJsonPath('data.summary.participant_count', 2)
            ->assertJsonPath('data.summary.passed_count', 2);

        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/exams/{$exam->id}/statistics?workplace_id={$otherWorkplace->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.attempt_count', 1)
            ->assertJsonPath('data.summary.participant_count', 1);
    }

    /**
     * @param list<Workplace> $workplaces
     */
    private function recurringExamWithOneQuestion(array $workplaces): Exam
    {
        $exam = Exam::create([
            'is_required' => true,
            'is_recurring' => true,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 60,
            'languages' => ['en', 'vi', 'ja'],
        ]);
        $exam->syncTranslations(['en' => 'Exam', 'vi' => 'Bài thi', 'ja' => '試験']);
        $exam->workplaces()->attach(collect($workplaces)->pluck('id')->all());

        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(['en' => 'Q', 'vi' => 'Câu', 'ja' => '問']);
        foreach ([true, false] as $isCorrect) {
            $option = $question->options()->create(['is_correct' => $isCorrect]);
            $option->syncTranslations(['en' => 'A', 'vi' => 'A', 'ja' => 'A']);
        }

        return $exam;
    }

    private function takeExam(User $employee, Exam $exam, bool $correct): void
    {
        $this->actingAs($employee, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertOk();

        $question = $exam->questions()->firstOrFail();
        $option = QuestionOption::query()
            ->where('question_id', $question->id)
            ->where('is_correct', $correct)
            ->firstOrFail();

        $this->actingAs($employee, 'api')
            ->postJson("/user/exams/{$exam->id}/submit", [
                'answers' => [['question_id' => $question->id, 'option_id' => $option->id]],
                'is_final' => true,
            ])
            ->assertOk();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => $this->langPayload('Recurring exam'),
            'languages' => Locale::values(),
            'is_required' => true,
            'is_recurring' => true,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [$this->workplace->id],
            'documents' => [],
            'questions' => [
                [
                    'id' => null,
                    'content' => $this->langPayload('Q1?'),
                    'options' => [
                        ['id' => null, 'content' => $this->langPayload('A'), 'is_correct' => true],
                        ['id' => null, 'content' => $this->langPayload('B'), 'is_correct' => false],
                    ],
                ],
            ],
        ];
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }
}
