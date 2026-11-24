<?php

namespace Tests\Feature\Api\User;

use App\Actions\Admin\Exam\GetExamCompletionStatsAction;
use App\Actions\Common\Exam\GetPendingRequiredExamsAction;
use App\Enums\ExamType;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use App\Services\ExamNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamShiftEligibilityTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private Workplace $workplace;

    private Workplace $otherWorkplace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workplace = $this->makeWorkplace('Tokyo');
        $this->otherWorkplace = $this->makeWorkplace('Osaka');
        $this->user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $this->user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));
        $this->user->workplaces()->sync([$this->workplace->id, $this->otherWorkplace->id]);
    }

    public function testShiftFifteenDaysAgoStillQualifies(): void
    {
        $exam = $this->makeExam();
        $this->giveExamShift($this->user, $this->workplace, now()->subDays(15)->toDateString());

        $this->assertSeesExam($exam);
    }

    public function testShiftSixteenDaysAgoNoLongerQualifies(): void
    {
        $exam = $this->makeExam();
        $this->giveExamShift($this->user, $this->workplace, now()->subDays(16)->toDateString());

        $this->assertDoesNotSeeExam($exam);
    }

    public function testApprovedFutureShiftQualifiesEvenWithOldHistory(): void
    {
        $exam = $this->makeExam();
        $this->giveExamShift($this->user, $this->workplace, now()->subDays(60)->toDateString());
        $this->giveExamShift($this->user, $this->workplace, now()->addDays(40)->toDateString());

        $this->assertSeesExam($exam);
    }

    public function testPendingRejectedOrAbsentShiftsDoNotQualify(): void
    {
        $exam = $this->makeExam();
        $this->giveExamShift($this->user, $this->workplace, status: ShiftUserStatus::Pending);
        $this->giveExamShift($this->user, $this->workplace, status: ShiftUserStatus::Rejected);
        $this->giveExamShift($this->user, $this->workplace, isAbsent: true);

        $this->assertDoesNotSeeExam($exam);
    }

    public function testShiftAtAnotherWorkplaceDoesNotQualifyForThisWorkplacesExam(): void
    {
        $exam = $this->makeExam();
        $this->giveExamShift($this->user, $this->otherWorkplace);

        $this->assertDoesNotSeeExam($exam);
    }

    public function testRequiredExamIsNotPendingAtLoginWithoutRecentShift(): void
    {
        $exam = $this->makeExam(['is_required' => true]);

        $this->assertNotContains($exam->id, $this->pendingExamIds());

        $this->giveExamShift($this->user, $this->workplace);

        $this->assertContains($exam->id, $this->pendingExamIds());
    }

    public function testExternalFormPrepExamIgnoresTheShiftRule(): void
    {
        $exam = $this->makeExam(['type' => ExamType::ExternalFormPrep->value]);

        $this->assertSeesExam($exam);
    }

    public function testAttemptStartedWhileActiveCanStillBeFinishedAfterTheWindowPasses(): void
    {
        $exam = $this->makeExam(['time_limit' => 60 * 24 * 30]);
        $question = $this->makeQuestion($exam);
        $this->giveExamShift($this->user, $this->workplace);

        $this->actingAs($this->user, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertStatus(200);
        $this->travel(20)->days();

        $this->assertSeesExam($exam);
        $this->assertNotContains($exam->id, $this->pendingExamIds());
        $this->actingAs($this->user, 'api')
            ->postJson("/user/exams/{$exam->id}/submit", [
                'answers' => [['question_id' => $question->id, 'option_id' => $question->options()->first()->id]],
                'is_final' => true,
            ])
            ->assertStatus(200);
    }

    public function testNotificationRecipientsFollowTheShiftRule(): void
    {
        $exam = $this->makeExam(['is_required' => true]);
        $inactive = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $inactive->workplaces()->sync([$this->workplace->id]);
        $this->giveExamShift($this->user, $this->workplace);

        $recipientIds = app(ExamNotificationService::class)->getRecipientsForExam($exam->fresh())->pluck('id')->all();

        $this->assertEquals([$this->user->id], $recipientIds);
    }

    public function testFailedAttemptAfterTheWindowPassesKeepsTheExamVisibleButOutOfEveryPendingCount(): void
    {
        $exam = $this->makeExam(['is_required' => true, 'retry_limit' => 2]);
        $question = $this->makeQuestion($exam);
        $this->giveExamShift($this->user, $this->workplace);

        $this->actingAs($this->user, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertStatus(200);
        $this->actingAs($this->user, 'api')
            ->postJson("/user/exams/{$exam->id}/submit", [
                'answers' => [
                    [
                        'question_id' => $question->id,
                        'option_id' => $question->options()->where('is_correct', false)->value('id'),
                    ],
                ],
                'is_final' => true,
            ])
            ->assertStatus(200);
        $this->travel(20)->days();

        $this->assertSeesExam($exam);
        $this->assertNotContains($exam->id, $this->pendingExamIds());
        $this->assertNotContains(
            $this->user->id,
            app(ExamNotificationService::class)->getRecipientsForExam($exam->fresh())->pluck('id')->all()
        );
        $this->assertEquals(0, app(GetExamCompletionStatsAction::class)($exam->fresh())['pending_count']);
    }

    private function assertSeesExam(Exam $exam): void
    {
        $this->assertContains($exam->id, $this->listedExamIds());
        $this->actingAs($this->user, 'api')
            ->getJson("/user/exams/{$exam->id}")
            ->assertStatus(200);
    }

    private function assertDoesNotSeeExam(Exam $exam): void
    {
        $this->assertNotContains($exam->id, $this->listedExamIds());
        $this->actingAs($this->user, 'api')
            ->getJson("/user/exams/{$exam->id}")
            ->assertStatus(403);
    }

    private function listedExamIds(): array
    {
        return array_column($this->actingAs($this->user, 'api')->getJson('/user/exams')->json('data.data'), 'id');
    }

    private function pendingExamIds(): array
    {
        return app(GetPendingRequiredExamsAction::class)($this->user->fresh())
            ->pluck('id')
            ->all();
    }

    private function makeExam(array $overrides = []): Exam
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 1,
            'min_score' => 1,
            'time_limit' => 30,
            ...$overrides,
        ]);
        $exam->syncTranslations(['en' => 'Exam', 'vi' => 'Exam', 'ja' => 'Exam', 'ne' => 'Exam', 'si' => 'Exam']);
        $exam->workplaces()->sync([$this->workplace->id]);

        return $exam;
    }

    private function makeQuestion(Exam $exam): Question
    {
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(['en' => 'Q']);
        $question
            ->options()
            ->create(['is_correct' => true])
            ->syncTranslations(['en' => 'Yes']);
        $question
            ->options()
            ->create(['is_correct' => false])
            ->syncTranslations(['en' => 'No']);

        return $question;
    }

    private function makeWorkplace(string $name): Workplace
    {
        return Workplace::create([
            'name' => $name,
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }
}
