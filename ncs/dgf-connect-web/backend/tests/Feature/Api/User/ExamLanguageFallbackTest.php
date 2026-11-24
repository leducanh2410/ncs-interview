<?php

namespace Tests\Feature\Api\User;

use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

/**
 * End-to-end coverage of DGFC-119 requirement 3: an exam that doesn't have
 * the requesting user's current language falls back to English. The
 * per-field fallback chain itself is unit-tested in HasTranslatableContentTest;
 * this proves the whole request path (SetLocale middleware -> GetExamAction /
 * GetExamResultAction -> Services\User\ExamService::resolveTitle/resolveContent)
 * actually reaches it now that an exam can genuinely lack a language (before
 * this feature every exam always had all 5 languages filled in).
 */
class ExamLanguageFallbackTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    public function testShowFallsBackToEnglishTitleWhenExamLacksUsersLanguage(): void
    {
        $user = $this->makeUserWithLocale('vi');
        $exam = $this->makeExam(['en' => 'English title', 'ja' => 'Japanese title'], ['en', 'ja']);

        $response = $this->actingAs($user, 'api')->getJson("/user/exams/{$exam->id}");

        $response->assertStatus(200);
        $this->assertEquals('English title', $response->json('data.title'));
    }

    public function testShowReturnsExactLanguageWhenExamHasIt(): void
    {
        $user = $this->makeUserWithLocale('ja');
        $exam = $this->makeExam(['en' => 'English title', 'ja' => 'Japanese title'], ['en', 'ja']);

        $response = $this->actingAs($user, 'api')->getJson("/user/exams/{$exam->id}");

        $response->assertStatus(200);
        $this->assertEquals('Japanese title', $response->json('data.title'));
    }

    /**
     * Same fallback chain, exercised through the external_form_prep result
     * endpoint so the per-language link (external_url) is covered too, not
     * just the title.
     */
    public function testResultFallsBackToEnglishExternalUrlWhenExamLacksUsersLanguage(): void
    {
        $user = $this->makeUserWithLocale('vi');
        $exam = $this->makeExternalFormPrepExam(
            ['en' => 'https://example.com/en-form', 'ja' => 'https://example.com/ja-form'],
            ['en', 'ja']
        );
        $question = $this->makeQuestionWithOptions($exam);

        $this->actingAs($user, 'api')->postJson("/user/exams/{$exam->id}/start");
        $this->actingAs($user, 'api')->postJson("/user/exams/{$exam->id}/submit", [
            'answers' => [['question_id' => $question->id, 'option_id' => $question->options()->first()->id]],
            'is_final' => true,
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/user/exams/{$exam->id}/result");

        $response->assertStatus(200);
        $this->assertEquals('https://example.com/en-form', $response->json('data.external.url'));
    }

    private function makeUserWithLocale(string $lang): User
    {
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'settings' => ['lang' => $lang],
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $user->addRole($role);
        // An employee without a workplace is part of no exam's audience, so
        // they would be denied every exam endpoint before reaching the
        // language fallback under test here.
        $user->workplaces()->attach($this->makeWorkplace()->id);
        $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));

        return $user;
    }

    private function makeWorkplace(): Workplace
    {
        return Workplace::create([
            'name' => 'Tokyo',
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }

    private function makeExam(array $title, array $languages, array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'languages' => $languages,
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
        $exam->replaceTranslations($title, $languages, 'title');

        return $exam;
    }

    private function makeExternalFormPrepExam(array $externalUrl, array $languages): Exam
    {
        $exam = Exam::create([
            'languages' => $languages,
            'type' => 'external_form_prep',
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 120,
            'start_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'end_at' => null,
        ]);
        $exam->replaceTranslations(array_fill_keys($languages, 'External form prep exam'), $languages, 'title');
        $exam->replaceTranslations($externalUrl, $languages, 'external_url');

        return $exam;
    }

    private function makeQuestionWithOptions(Exam $exam): Question
    {
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations(array_fill_keys($exam->languages, 'Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations(array_fill_keys($exam->languages, 'A'));

        return $question;
    }
}
