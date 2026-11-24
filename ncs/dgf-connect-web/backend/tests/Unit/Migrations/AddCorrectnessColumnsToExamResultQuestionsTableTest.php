<?php

namespace Tests\Unit\Migrations;

use App\Enums\ExamResultStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddCorrectnessColumnsToExamResultQuestionsTableTest extends TestCase
{
    use RefreshDatabase;

    public function testUpKeepsTheCorrectAnswerThatTheStoredScoreCountedAndBackfillsCorrectness(): void
    {
        $migration = $this->migration();
        $migration->down();

        $exam = Exam::create(['is_required' => false, 'retry_limit' => 0, 'min_score' => 1, 'time_limit' => 30]);
        $question = Question::create(['exam_id' => $exam->id]);
        $correct = $question->options()->create(['is_correct' => true]);
        $wrong = $question->options()->create(['is_correct' => false]);
        $otherQuestion = Question::create(['exam_id' => $exam->id]);
        $otherCorrect = $otherQuestion->options()->create(['is_correct' => true]);
        $result = $this->makeResult($exam, score: 2);

        $correctId = $this->insertAnswer($result->id, $question->id, $correct->id);
        $laterWrongId = $this->insertAnswer($result->id, $question->id, $wrong->id);
        $otherId = $this->insertAnswer($result->id, $otherQuestion->id, $otherCorrect->id);

        $migration->up();

        $this->assertDatabaseHas('exam_result_questions', ['id' => $correctId, 'is_correct' => true]);
        $this->assertDatabaseMissing('exam_result_questions', ['id' => $laterWrongId]);
        $this->assertDatabaseHas('exam_result_questions', ['id' => $otherId, 'is_correct' => true]);
        $this->assertEquals(
            $result->fresh()->score,
            DB::table('exam_result_questions')->where('exam_result_id', $result->id)->where('is_correct', true)->count()
        );
    }

    public function testUpKeepsTheNewestAnswerWhenNoDuplicateIsCorrect(): void
    {
        $migration = $this->migration();
        $migration->down();

        $exam = Exam::create(['is_required' => false, 'retry_limit' => 0, 'min_score' => 1, 'time_limit' => 30]);
        $question = Question::create(['exam_id' => $exam->id]);
        $question->options()->create(['is_correct' => true]);
        $firstWrong = $question->options()->create(['is_correct' => false]);
        $secondWrong = $question->options()->create(['is_correct' => false]);
        $result = $this->makeResult($exam, score: 0);

        $staleId = $this->insertAnswer($result->id, $question->id, $firstWrong->id);
        $latestId = $this->insertAnswer($result->id, $question->id, $secondWrong->id);

        $migration->up();

        $this->assertDatabaseMissing('exam_result_questions', ['id' => $staleId]);
        $this->assertDatabaseHas('exam_result_questions', ['id' => $latestId, 'is_correct' => false]);
    }

    private function makeResult(Exam $exam, int $score): ExamResult
    {
        return ExamResult::create([
            'user_id' => User::factory()->create()->id,
            'exam_id' => $exam->id,
            'score' => $score,
            'total_questions' => 2,
            'correct_answers' => $score,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::Completed->value,
        ]);
    }

    private function insertAnswer(int $resultId, int $questionId, int $optionId): int
    {
        return DB::table('exam_result_questions')->insertGetId([
            'exam_result_id' => $resultId,
            'question_id' => $questionId,
            'question_option_id' => $optionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_09_24_000003_add_correctness_columns_to_exam_result_questions_table.php'
        );
    }
}
