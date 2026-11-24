<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExamResultStatus;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamResultDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(
            ['name' => PermissionName::ExamResultList->value],
            ['display_name' => PermissionName::ExamResultList->value]
        );
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(
                Permission::query()->where('name', PermissionName::ExamResultList->value)->pluck('id')->all()
            );
        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
    }

    public function testShowReturnsTitleAndQuestionContentForEveryLanguage(): void
    {
        $examResult = $this->makeExamWithResult();

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exam-results/{$examResult->id}");

        $response->assertStatus(200);
        $this->assertEquals('Exam title', $response->json('data.exam_result.exam.title.en'));
        $this->assertEquals('Bai thi', $response->json('data.exam_result.exam.title.vi'));
        $this->assertEquals('Q1?', $response->json('data.questions.0.content.en'));
        $this->assertEquals('A', $response->json('data.questions.0.options.0.content.en'));
    }

    public function testShowReturnsExternalUrlForEveryLanguageOnExternalFormPrepExam(): void
    {
        $exam = Exam::create([
            'type' => 'external_form_prep',
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'External exam'));
        $exam->syncTranslations(
            [
                'en' => 'https://docs.google.com/forms/d/e/example-en/viewform',
                'vi' => 'https://docs.google.com/forms/d/e/example-vi/viewform',
                'ja' => 'https://docs.google.com/forms/d/e/example-ja/viewform',
                'ne' => 'https://docs.google.com/forms/d/e/example-ne/viewform',
                'si' => 'https://docs.google.com/forms/d/e/example-si/viewform',
            ],
            'external_url'
        );

        $examResult = ExamResult::create([
            'user_id' => User::factory()->create(['paper_status' => UserPaperStatus::Approved->value])->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 0,
            'correct_answers' => 0,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::Completed,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/admin/exam-results/{$examResult->id}");

        $response->assertStatus(200);
        $this->assertEquals(
            'https://docs.google.com/forms/d/e/example-en/viewform',
            $response->json('data.exam_result.exam.external_url.en')
        );
        $this->assertEquals(
            'https://docs.google.com/forms/d/e/example-vi/viewform',
            $response->json('data.exam_result.exam.external_url.vi')
        );
    }

    public function testIndexReturnsExamTitleForEveryLanguage(): void
    {
        $examResult = $this->makeExamWithResult();

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exam-results');

        $response->assertStatus(200);
        $row = collect($response->json('data.data'))->firstWhere('id', $examResult->id);
        $this->assertNotNull($row, 'Expected the exam result to be present in the list response');
        $this->assertEquals('Exam title', $row['exam']['title']['en']);
        $this->assertEquals('Bai thi', $row['exam']['title']['vi']);
    }

    public function testIndexTitleFilterMatchesAnySupportedLanguage(): void
    {
        $examResult = $this->makeExamWithResult();

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exam-results?title=Bai+thi');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEquals([$examResult->id], $ids);
    }

    /**
     * `exam_translations` also stores `external_url` rows for
     * external_form_prep exams - the title filter must not match a string
     * that only appears in the Google Form link, or a search term that
     * happens to overlap with a URL would surface results whose exam title
     * doesn't match at all.
     */
    public function testIndexTitleFilterDoesNotMatchExternalUrl(): void
    {
        $exam = Exam::create([
            'type' => 'external_form_prep',
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'External exam'));
        $exam->syncTranslations(
            array_fill_keys(Locale::values(), 'https://docs.google.com/forms/d/e/uniquematchtoken/viewform'),
            'external_url'
        );
        ExamResult::create([
            'user_id' => User::factory()->create(['paper_status' => UserPaperStatus::Approved->value])->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 0,
            'correct_answers' => 0,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::Completed,
        ]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exam-results?title=uniquematchtoken');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEmpty($ids);
    }

    private function makeExamWithResult(): ExamResult
    {
        $exam = Exam::create(['is_required' => false, 'retry_limit' => 0, 'min_score' => 1, 'time_limit' => 30]);
        $exam->syncTranslations([
            'vi' => 'Bai thi',
            'en' => 'Exam title',
            'ja' => 'Shiken',
            'ne' => 'Pariksha',
            'si' => 'Piksha',
        ]);
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(array_fill_keys(Locale::values(), 'Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations(array_fill_keys(Locale::values(), 'A'));

        return ExamResult::create([
            'user_id' => User::factory()->create(['paper_status' => UserPaperStatus::Approved->value])->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::Completed,
        ]);
    }
}
