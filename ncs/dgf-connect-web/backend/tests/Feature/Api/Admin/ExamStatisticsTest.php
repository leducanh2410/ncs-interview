<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamStatisticsTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    private Exam $exam;

    /** @var Question[] */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            PermissionName::ExamList->value,
            PermissionName::ExamEdit->value,
            PermissionName::ExamResultList->value,
        ];
        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $this->admin = User::factory()->create();
        $this->admin->addRole($role);

        $this->workplace = Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);

        $this->exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 3,
            'min_score' => 2,
            'time_limit' => 30,
        ]);
        $this->exam->syncTranslations($this->langPayload('Exam'));
        $this->exam->workplaces()->sync([$this->workplace->id]);

        foreach ([1, 2] as $number) {
            $question = Question::create(['exam_id' => $this->exam->id]);
            $question->syncTranslations($this->langPayload("Q{$number}"));
            $question
                ->options()
                ->create(['is_correct' => true])
                ->syncTranslations($this->langPayload('Yes'));
            $question
                ->options()
                ->create(['is_correct' => false])
                ->syncTranslations($this->langPayload('No'));
            $this->questions[$number] = $question;
        }
    }

    public function testCountsCorrectWrongAndUnansweredPerQuestionWithoutDoubleCountingCarriedAnswers(): void
    {
        $retaker = $this->makeEmployee();
        $this->takeExam($retaker, [1 => 'correct', 2 => 'wrong']);
        $this->takeExam($retaker, [2 => 'correct']);

        $skipper = $this->makeEmployee();
        $this->takeExam($skipper, [1 => 'wrong']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exams/{$this->exam->id}/statistics");

        $response->assertStatus(200);
        $this->assertEquals(
            [
                'total_questions' => 2,
                'min_score' => 2,
                'attempt_count' => 3,
                'participant_count' => 2,
                'passed_count' => 1,
                'pass_rate' => 0.5,
                'average_score' => 1,
            ],
            $response->json('data.summary')
        );

        $first = $response->json('data.questions.0');
        $this->assertEquals(1, $first['no']);
        $this->assertEquals('Q1', $first['content']['en']);
        $this->assertEquals(1, $first['correct_count']);
        $this->assertEquals(1, $first['wrong_count']);
        $this->assertEquals(0, $first['unanswered_count']);
        $this->assertEquals([1, 1], array_column($first['options'], 'selected_count'));

        $second = $response->json('data.questions.1');
        $this->assertEquals(1, $second['correct_count']);
        $this->assertEquals(1, $second['wrong_count']);
        $this->assertEquals(1, $second['unanswered_count']);
        $this->assertEquals(0.3333, $second['correct_rate']);
    }

    public function testFiltersByCurrentWorkplaceMembership(): void
    {
        $stayed = $this->makeEmployee();
        $this->takeExam($stayed, [1 => 'correct', 2 => 'wrong']);
        $inWorkplace = $this->makeEmployee();
        $this->takeExam($inWorkplace, [1 => 'correct', 2 => 'correct']);
        $otherWorkplace = Workplace::create([
            'name' => 'Osaka',
            'address' => '2-2-2',
            'latitude' => '34.0',
            'longitude' => '135.0',
        ]);
        $inWorkplace->workplaces()->sync([$otherWorkplace->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson(
            "/admin/exams/{$this->exam->id}/statistics?workplace_id={$this->workplace->id}"
        );

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.summary.attempt_count'));
        $this->assertEquals(1, $response->json('data.summary.participant_count'));
        $this->assertEquals(1, $response->json('data.questions.1.wrong_count'));
        $this->assertEquals(0, $response->json('data.questions.1.correct_count'));
    }

    public function testRequiresExamResultListPermission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson("/admin/exams/{$this->exam->id}/statistics")
            ->assertStatus(403);
    }

    public function testRejectsInvalidDateRange(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson("/admin/exams/{$this->exam->id}/statistics?from=2026-09-10&to=2026-09-01")
            ->assertStatus(422);
    }

    public function testQuestionStructureIsLockedOnceTheExamHasResults(): void
    {
        $this->takeExam($this->makeEmployee(), [1 => 'correct']);

        $addQuestion = $this->updatePayload();
        $addQuestion['questions'][] = [
            'id' => null,
            'content' => $this->langPayload('Q3'),
            'options' => [
                ['id' => null, 'content' => $this->langPayload('Yes'), 'is_correct' => true],
                ['id' => null, 'content' => $this->langPayload('No'), 'is_correct' => false],
            ],
        ];
        $this->actingAs($this->admin, 'api')
            ->putJson("/admin/exams/{$this->exam->id}", $addQuestion)
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['questions']]);

        $flipCorrect = $this->updatePayload();
        $flipCorrect['questions'][0]['options'][0]['is_correct'] = false;
        $flipCorrect['questions'][0]['options'][1]['is_correct'] = true;
        $this->actingAs($this->admin, 'api')
            ->putJson("/admin/exams/{$this->exam->id}", $flipCorrect)
            ->assertStatus(422);

        $removeQuestion = $this->updatePayload();
        array_pop($removeQuestion['questions']);
        $removeQuestion['min_score'] = 1;
        $this->actingAs($this->admin, 'api')
            ->putJson("/admin/exams/{$this->exam->id}", $removeQuestion)
            ->assertStatus(422);

        $editText = $this->updatePayload();
        $editText['questions'][0]['content'] = $this->langPayload('Q1 edited');
        $this->actingAs($this->admin, 'api')
            ->putJson("/admin/exams/{$this->exam->id}", $editText)
            ->assertStatus(200);
        $this->assertEquals('Q1 edited', $this->questions[1]->fresh()->translation('en'));
        $this->assertEquals(1, $this->exam->results()->count());
    }

    public function testShowExposesHasResultsAndShowResultImmediately(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exams/{$this->exam->id}");
        $this->assertFalse($response->json('data.has_results'));
        $this->assertFalse($response->json('data.show_result_immediately'));

        $payload = $this->updatePayload();
        $payload['show_result_immediately'] = true;
        $this->actingAs($this->admin, 'api')
            ->putJson("/admin/exams/{$this->exam->id}", $payload)
            ->assertStatus(200);
        $this->takeExam($this->makeEmployee(), [1 => 'correct']);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exams/{$this->exam->id}");
        $this->assertTrue($response->json('data.has_results'));
        $this->assertTrue($response->json('data.show_result_immediately'));
    }

    public function testCompletionStatsKeepPassedEmployeesWithoutRecentShifts(): void
    {
        $passed = $this->makeEmployee();
        $this->takeExam($passed, [1 => 'correct', 2 => 'correct']);
        $this->makeEmployee();
        $inactive = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inactive->workplaces()->sync([$this->workplace->id]);

        $this->travel(20)->days();

        $row = collect($this->actingAs($this->admin, 'api')->getJson('/admin/exams')->json('data.data'))->firstWhere(
            'id',
            $this->exam->id
        );
        $this->assertEquals(1, $row['completion_stats']['eligible_count']);
        $this->assertEquals(1, $row['completion_stats']['completed_count']);
    }

    private function makeEmployee(): User
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));
        $user->workplaces()->sync([$this->workplace->id]);
        $this->giveExamShift($user, $this->workplace);

        return $user;
    }

    /**
     * @param array<int, string> $answers
     */
    private function takeExam(User $user, array $answers): void
    {
        $this->actingAs($user, 'api')
            ->postJson("/user/exams/{$this->exam->id}/start")
            ->assertStatus(200);
        $this->actingAs($user, 'api')
            ->postJson("/user/exams/{$this->exam->id}/submit", [
                'answers' => collect($answers)
                    ->map(
                        fn(string $kind, int $number) => [
                            'question_id' => $this->questions[$number]->id,
                            'option_id' => $this->questions[$number]
                                ->options()
                                ->where('is_correct', $kind === 'correct')
                                ->value('id'),
                        ]
                    )
                    ->values()
                    ->all(),
                'is_final' => true,
            ])
            ->assertStatus(200);
    }

    private function updatePayload(): array
    {
        return [
            'title' => $this->langPayload('Exam'),
            'languages' => Locale::values(),
            'is_required' => false,
            'retry_limit' => 3,
            'min_score' => 2,
            'time_limit' => 30,
            'workplaces' => [$this->workplace->id],
            'documents' => [],
            'questions' => collect($this->questions)
                ->values()
                ->map(
                    fn(Question $question) => [
                        'id' => $question->id,
                        'content' => $this->langPayload($question->translation('en')),
                        'options' => $question
                            ->options()
                            ->get()
                            ->map(
                                fn(QuestionOption $option) => [
                                    'id' => $option->id,
                                    'content' => $this->langPayload($option->translation('en')),
                                    'is_correct' => $option->is_correct,
                                ]
                            )
                            ->all(),
                    ]
                )
                ->all(),
        ];
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }
}
