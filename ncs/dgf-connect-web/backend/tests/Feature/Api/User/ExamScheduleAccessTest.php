<?php

namespace Tests\Feature\Api\User;

use App\Enums\ErrorCode;
use App\Enums\ExamResultStatus;
use App\Enums\Locale;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamScheduleAccessTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $this->user->addRole($role);

        $workplace = Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
        $this->user->workplaces()->attach($workplace->id);
        $this->giveExamShifts($this->user, $this->user->workplaces()->pluck('workplaces.id'));
    }

    public function testUserExamListOnlyReturnsActiveWindowExams(): void
    {
        $active = $this->makeExam('Active');
        $this->makeExam('Scheduled', [
            'start_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);
        $this->makeExam('Ended', [
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/user/exams');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEquals([$active->id], $ids);
        $this->assertEquals('Active', $response->json('data.data.0.title'));
    }

    /**
     * ExamController::index() never passes a page_size, so the empty-page
     * shortcut GetExamsAction takes for a user with no workplace used to build
     * a LengthAwarePaginator with a null per-page and 500 with
     * DivisionByZeroError. Every approved user who was never assigned a
     * workplace hit this on their first exam-list request.
     */
    public function testUserWithoutWorkplaceGetsEmptyExamListInsteadOfServerError(): void
    {
        $this->makeExam('Global');
        $this->user->workplaces()->detach();

        $response = $this->actingAs($this->user, 'api')->getJson('/user/exams');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.data'));
        $this->assertSame(0, $response->json('data.total'));
    }

    public function testUserExamListOrderedByStartAtDescending(): void
    {
        // Created in an order where id ordering diverges from start_at
        // ordering, so this only passes if the endpoint truly sorts by
        // start_at desc rather than id desc.
        $middle = $this->makeExam('Middle', ['start_at' => now()->subHour()->format('Y-m-d H:i:s')]);
        $newest = $this->makeExam('Newest', ['start_at' => now()->subMinutes(5)->format('Y-m-d H:i:s')]);
        $oldest = $this->makeExam('Oldest', ['start_at' => now()->subHours(3)->format('Y-m-d H:i:s')]);

        $response = $this->actingAs($this->user, 'api')->getJson('/user/exams');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function testPendingExamsOrderedByStartAtDescendingWithIdTieBreaker(): void
    {
        $sharedStartAt = now()->subHours(2)->format('Y-m-d H:i:s');

        $first = $this->makeExam('First', [
            'is_required' => true,
            'start_at' => $sharedStartAt,
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);
        $second = $this->makeExam('Second', [
            'is_required' => true,
            'start_at' => $sharedStartAt,
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);
        $newest = $this->makeExam('Newest', [
            'is_required' => true,
            'start_at' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200);
        $ids = collect($response->json('data.pending_exams'))->pluck('id')->all();
        // $second has the larger id (created after $first) and both share
        // start_at, so the id desc tie-breaker must place $second before
        // $first.
        $this->assertSame([$newest->id, $second->id, $first->id], $ids);
    }

    public function testUserCannotOpenScheduledExamDirectly(): void
    {
        $exam = $this->makeExam('Exam', [
            'start_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$exam->id}");

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::Forbidden->value);
    }

    public function testUserCannotOpenEndedExamDirectly(): void
    {
        $exam = $this->makeExam('Exam', [
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson("/user/exams/{$exam->id}");

        $response->assertStatus(403)->assertJsonPath('error', ErrorCode::Forbidden->value);
    }

    public function testRequiredExamAfterEndAtIsReturnedUntilUserPasses(): void
    {
        $endAt = now()->subHour()->format('Y-m-d H:i:s');
        $exam = $this->makeExam('Exam', [
            'is_required' => true,
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_at' => $endAt,
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200);
        $this->assertEquals([$exam->id], collect($response->json('data.pending_exams'))->pluck('id')->all());
        $this->assertEquals($endAt, $response->json('data.pending_exams.0.end_at'));
        $this->assertEquals('Exam', $response->json('data.pending_exams.0.title'));
    }

    public function testPassedRequiredExamIsNotReturnedInAuthPendingExams(): void
    {
        $exam = $this->makeExam('Exam', [
            'is_required' => true,
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'min_score' => 50,
        ]);
        ExamResult::create([
            'user_id' => $this->user->id,
            'exam_id' => $exam->id,
            'score' => 80,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::Completed,
        ]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200)->assertJsonPath('data.pending_exams', []);
    }

    public function testRequiredExamCanBeRetakenAfterEndAtAndRetryLimitUntilPassed(): void
    {
        $exam = $this->makeExam('Exam', [
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 1,
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);
        $this->makeQuestionWithOptions($exam);
        ExamResult::create([
            'user_id' => $this->user->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => now()->subMinutes(30),
            'status' => ExamResultStatus::Completed,
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$exam->id}/start");

        $response->assertStatus(200);
        $this->assertEquals('Q1', $response->json('data.questions.0.content'));
    }

    public function testUserExamListDoesNotLeakLoadedTranslationsRelation(): void
    {
        $this->makeExam('Active');

        $response = $this->actingAs($this->user, 'api')->getJson('/user/exams');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('translations', $response->json('data.data.0'));
    }

    public function testStartExamDoesNotLeakLoadedTranslationsRelation(): void
    {
        $exam = $this->makeExam('Exam', [
            'is_required' => false,
            'retry_limit' => 1,
            'min_score' => 1,
        ]);
        $this->makeQuestionWithOptions($exam);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$exam->id}/start");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('translations', $data);
        $this->assertArrayNotHasKey('translations', $data['questions'][0]);
        $this->assertArrayNotHasKey('translations', $data['questions'][0]['options'][0]);
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }

    private function makeExam(string $title = 'Exam', array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'is_required' => false,
                    'retry_limit' => 0,
                    'min_score' => 50,
                    'time_limit' => 30,
                    'start_at' => now()->subHour()->format('Y-m-d H:i:s'),
                    'end_at' => now()->addHour()->format('Y-m-d H:i:s'),
                ],
                $overrides
            )
        );
        $exam->syncTranslations($this->langPayload($title));

        return $exam;
    }

    private function makeQuestionWithOptions(Exam $exam): Question
    {
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations($this->langPayload('Q1'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations($this->langPayload('A'));
        $wrong = $question->options()->create(['is_correct' => false]);
        $wrong->syncTranslations($this->langPayload('B'));

        return $question;
    }
}
