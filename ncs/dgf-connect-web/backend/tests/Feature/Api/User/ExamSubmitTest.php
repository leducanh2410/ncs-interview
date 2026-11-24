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
use Tests\TestCase;

class ExamSubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $this->user->addRole($role);
        // An employee without a workplace belongs to no exam's audience and is
        // denied every exam endpoint.
        $workplace = Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
        $this->user->workplaces()->attach($workplace->id);

        $this->exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 3,
            'min_score' => 2,
            'time_limit' => 60,
            'start_at' => null,
            'end_at' => null,
        ]);
        $this->exam->syncTranslations($this->langPayload('Test Exam'));

        $question = Question::create(['exam_id' => $this->exam->id]);
        $question->syncTranslations($this->langPayload('Q1'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations($this->langPayload('A'));
        $wrong = $question->options()->create(['is_correct' => false]);
        $wrong->syncTranslations($this->langPayload('B'));
    }

    public function testSubmitWithEmptyAnswersAndIsFinalRecordsFailedResult(): void
    {
        $result = $this->createInProgressResult();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => [],
            'is_final' => true,
        ]);

        $response->assertStatus(200);

        $result->refresh();
        $this->assertNotNull($result->completed_at);
        $this->assertEquals(ExamResultStatus::Failed->value, $result->status);
        $this->assertEquals(0, $result->score);
        $this->assertEquals(0, $result->correct_answers);
    }

    public function testSubmitWithEmptyAnswersNotFinalDoesNotCompleteResult(): void
    {
        $result = $this->createInProgressResult();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => [],
            'is_final' => false,
        ]);

        $response->assertStatus(200);

        $result->refresh();
        $this->assertNull($result->completed_at);
        $this->assertEquals(ExamResultStatus::InProgress->value, $result->status);
    }

    public function testSubmitAfterExamEndAtIsRejectedEvenWithActiveSession(): void
    {
        $this->exam->update([
            'end_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);
        $this->createInProgressResult();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/exams/{$this->exam->id}/submit", [
            'answers' => [],
            'is_final' => false,
        ]);

        $response->assertStatus(400)->assertJsonPath('error', ErrorCode::ExamTimeOutOfRange->value);
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }

    private function createInProgressResult(): ExamResult
    {
        return ExamResult::create([
            'user_id' => $this->user->id,
            'exam_id' => $this->exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(60),
            'status' => ExamResultStatus::InProgress,
        ]);
    }
}
