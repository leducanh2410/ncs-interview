<?php

namespace Tests\Feature\Api\User;

use App\Actions\Common\Exam\RecordRecurringExamCompletionAction;
use App\Enums\ExamResultStatus;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Jobs\ExamResultCalculation;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use App\Services\User\ExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamAttemptFlowTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private User $user;

    private Exam $exam;

    /** @var Question[] */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'settings' => ['lang' => 'vi'],
        ]);
        $this->user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));
        $workplace = Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
        $this->user->workplaces()->attach($workplace->id);
        $this->giveExamShift($this->user, $workplace);

        $this->exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 5,
            'min_score' => 3,
            'time_limit' => 60,
            'languages' => ['en', 'vi', 'ja'],
        ]);
        $this->exam->syncTranslations(['en' => 'Exam', 'vi' => 'Bài thi', 'ja' => '試験']);
        $this->exam->workplaces()->attach($workplace->id);

        foreach ([1, 2, 3] as $number) {
            $question = Question::create(['exam_id' => $this->exam->id]);
            $question->syncTranslations(['en' => "Q{$number}", 'vi' => "Câu {$number}", 'ja' => "問{$number}"]);
            $yes = $question->options()->create(['is_correct' => $number !== 2]);
            $yes->syncTranslations(['en' => 'Yes', 'vi' => 'Có', 'ja' => 'はい']);
            $no = $question->options()->create(['is_correct' => $number === 2]);
            $no->syncTranslations(['en' => 'No', 'vi' => 'Không', 'ja' => 'いいえ']);
            $this->questions[$number] = $question;
        }
    }

    public function testChangingAnAnswerReplacesThePreviousOneInsteadOfAddingARow(): void
    {
        $this->start();
        $this->submit([[1, 'correct']]);
        $this->submit([[1, 'wrong']]);
        $this->submit([[2, 'correct'], [3, 'correct']], true);

        $result = $this->latestResult();
        $this->assertEquals(
            1,
            $result
                ->questions()
                ->where('question_id', $this->questions[1]->id)
                ->count()
        );
        $this->assertEquals(2, $result->score);
        $this->assertEquals(ExamResultStatus::Failed->value, $result->status);
        $this->assertFalse(
            $result
                ->questions()
                ->where('question_id', $this->questions[1]->id)
                ->value('is_correct')
        );
    }

    public function testAnsweringTheSameQuestionTwiceInOneRequestKeepsOnlyTheLastAnswer(): void
    {
        $this->start();
        $this->submit([[1, 'correct']]);
        $this->submit([[1, 'wrong'], [1, 'correct'], [2, 'correct'], [3, 'correct']], true);

        $result = $this->latestResult();
        $this->assertEquals(3, $result->questions()->count());
        $this->assertTrue(
            $result
                ->questions()
                ->where('question_id', $this->questions[1]->id)
                ->value('is_correct')
        );
        $this->assertEquals(3, $result->score);
        $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
    }

    public function testOptionsKeepTheAdminOrderAndQuestionOrderIsStableAcrossResumes(): void
    {
        $first = $this->start();

        foreach ($first->json('data.questions') as $question) {
            $this->assertEquals(['Có', 'Không'], array_column($question['options'], 'content'));
            $this->assertArrayNotHasKey('is_correct', $question['options'][0]);
        }

        $firstOrder = array_column($first->json('data.questions'), 'id');
        foreach (range(1, 5) as $ignored) {
            $this->assertEquals($firstOrder, array_column($this->start()->json('data.questions'), 'id'));
        }
    }

    public function testRetakeOnlyAsksTheQuestionsThatWereWrongAndCountsCarriedAnswers(): void
    {
        $this->start();
        $this->submit([[1, 'correct'], [2, 'wrong'], [3, 'correct']], true);
        $this->assertEquals(ExamResultStatus::Failed->value, $this->latestResult()->status);

        $retake = $this->start();

        $retake->assertStatus(200);
        $this->assertEquals([$this->questions[2]->id], array_column($retake->json('data.questions'), 'id'));
        $this->assertEquals(2, $retake->json('data.carried_over_count'));
        $this->assertEquals(3, $retake->json('data.result.total_questions'));

        $this->submit([[1, 'wrong']]);
        $this->submit([[2, 'correct']], true);

        $result = $this->latestResult();
        $this->assertEquals(3, $result->score);
        $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
        $this->assertTrue(
            $result
                ->questions()
                ->where('question_id', $this->questions[1]->id)
                ->value('is_carried_over')
        );
        $this->assertTrue(
            $result
                ->questions()
                ->where('question_id', $this->questions[1]->id)
                ->value('is_correct')
        );
    }

    public function testThirdAttemptCarriesAnswersAccumulatedOverEveryEarlierAttempt(): void
    {
        $this->start();
        $this->submit([[1, 'correct'], [2, 'wrong'], [3, 'wrong']], true);

        $this->start();
        $this->submit([[2, 'correct'], [3, 'wrong']], true);
        $this->assertEquals(2, $this->latestResult()->score);

        $third = $this->start();

        $this->assertEquals([$this->questions[3]->id], array_column($third->json('data.questions'), 'id'));
        $this->assertEquals(2, $third->json('data.carried_over_count'));
    }

    public function testExpiredRetakeIsGradedWithCarriedAnswersByTheCalculationJob(): void
    {
        $this->start();
        $this->submit([[1, 'correct'], [2, 'correct'], [3, 'wrong']], true);

        $this->start();
        $this->submit([[3, 'correct']]);
        $this->travel(61)->minutes();

        (new ExamResultCalculation())->handle(app(ExamService::class), app(RecordRecurringExamCompletionAction::class));

        $result = $this->latestResult();
        $this->assertEquals(3, $result->score);
        $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
    }

    public function testDraftAndUnrevealedAnswersNeverExposeCorrectness(): void
    {
        $this->start();
        $this->submit([[1, 'wrong']]);

        $detail = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}");
        $this->assertArrayNotHasKey('is_correct', $detail->json('data.result.questions.0'));
        $this->assertArrayNotHasKey('is_correct', $this->start()->json('data.result.questions.0'));

        $this->submit([[2, 'correct']], true);

        $detail = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}");
        $this->assertArrayNotHasKey('is_correct', $detail->json('data.result.questions.0'));
    }

    public function testResultIsHiddenUntilEndAtWhenShowResultImmediatelyIsOff(): void
    {
        $this->start();
        $submit = $this->submit([[1, 'correct']], true);

        $this->assertFalse($submit->json('data.can_view_result'));
        $this->actingAs($this->user, 'api')
            ->getJson("/user/exams/{$this->exam->id}/result")
            ->assertStatus(404);
        $this->assertFalse(
            $this->actingAs($this->user, 'api')
                ->getJson("/user/exams/{$this->exam->id}")
                ->json('data.can_view_result')
        );
    }

    public function testResultRevealsEveryCorrectAnswerRightAfterSubmitWhenShowResultImmediatelyIsOn(): void
    {
        $this->exam->update(['show_result_immediately' => true]);

        $this->start();
        $submit = $this->submit([[1, 'correct'], [2, 'wrong']], true);

        $this->assertTrue($submit->json('data.can_view_result'));
        $response = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}/result");
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.questions'));
        $rows = collect($response->json('data.result.questions'))->keyBy('question_id');
        $this->assertTrue($rows[$this->questions[1]->id]['is_correct']);
        $this->assertFalse($rows[$this->questions[2]->id]['is_correct']);
        $this->assertEquals($this->option(2, 'correct')->id, $rows[$this->questions[2]->id]['correct_option_id']);
    }

    public function testStartUsesTheChosenLanguageAndResumeKeepsIt(): void
    {
        $start = $this->start('ja');

        $this->assertEquals('試験', $start->json('data.title'));
        $this->assertEquals('ja', $start->json('data.result.lang'));
        $this->assertStringStartsWith('問', $start->json('data.questions.0.content'));

        $resume = $this->start('vi');

        $this->assertEquals('ja', $resume->json('data.result.lang'));
        $this->assertStringStartsWith('問', $resume->json('data.questions.0.content'));
    }

    public function testLanguageOutsideTheExamSelectionFallsBackToTheUserLocale(): void
    {
        $start = $this->start('si');

        $this->assertEquals('vi', $start->json('data.result.lang'));
        $this->assertEquals('Bài thi', $start->json('data.title'));
    }

    public function testDetailResolvesTheTitleInTheRequestedLanguageAndExposesTheSelection(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$this->exam->id}?lang=ja");

        $response->assertStatus(200);
        $this->assertEquals('試験', $response->json('data.title'));
        $this->assertEquals(['en', 'vi', 'ja'], $response->json('data.languages'));
    }

    public function testUnsupportedLanguageCodeIsRejected(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson("/user/exams/{$this->exam->id}/start", ['lang' => 'fr'])
            ->assertStatus(422);
    }

    private function start(?string $lang = null): TestResponse
    {
        $response = $this->actingAs($this->user, 'api')->postJson(
            "/user/exams/{$this->exam->id}/start",
            $lang ? ['lang' => $lang] : []
        );
        $response->assertStatus(200);

        return $response;
    }

    /**
     * @param array<int, array{0: int, 1: string}> $answers
     */
    private function submit(array $answers, bool $isFinal = false): TestResponse
    {
        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => array_map(
                fn(array $answer) => [
                    'question_id' => $this->questions[$answer[0]]->id,
                    'option_id' => $this->option($answer[0], $answer[1])->id,
                ],
                $answers
            ),
            'is_final' => $isFinal,
        ]);
        $response->assertStatus(200);

        return $response;
    }

    private function option(int $number, string $kind): QuestionOption
    {
        return $this->questions[$number]
            ->options()
            ->where('is_correct', $kind === 'correct')
            ->firstOrFail();
    }

    private function latestResult(): ExamResult
    {
        return ExamResult::query()
            ->where('exam_id', $this->exam->id)
            ->where('user_id', $this->user->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
