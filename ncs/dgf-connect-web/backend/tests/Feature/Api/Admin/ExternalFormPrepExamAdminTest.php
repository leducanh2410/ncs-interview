<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExamResultStatus;
use App\Enums\ExamType;
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
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalFormPrepExamAdminTest extends TestCase
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
                PermissionName::ExamResultList->value,
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
                    PermissionName::ExamResultList->value,
                ])
                ->pluck('id')
                ->all()
        );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
    }

    /**
     * The admin UI hides min_score/retry_limit/time_limit/is_required for
     * this type, so a real create payload never includes them - the server
     * must not fail on their absence (min_score/time_limit are NOT NULL
     * columns) and must persist safe defaults.
     */
    public function testCreateWithoutScoringFieldsPersistsServerAssignedDefaults(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('External form prep'),
            'languages' => Locale::values(),
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $examId = $response->json('data.id');
        $this->assertDatabaseHas('exams', [
            'id' => $examId,
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'min_score' => 0,
            'retry_limit' => 0,
            'time_limit' => 120,
        ]);
        $this->assertExamExternalUrl($examId, 'en', 'https://docs.google.com/forms/d/e/example/viewform');
    }

    /**
     * Defends against a crafted (non-UI) request: even if a client submits
     * min_score/retry_limit/time_limit/is_required for this type, the server
     * ignores them and applies the same safe defaults.
     */
    public function testCreateIgnoresSubmittedScoringFieldsForExternalFormPrep(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('External form prep crafted'),
            'languages' => Locale::values(),
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
            'is_required' => false,
            'min_score' => 42,
            'retry_limit' => 999,
            'time_limit' => 999,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'id' => $response->json('data.id'),
            'is_required' => true,
            'min_score' => 0,
            'retry_limit' => 0,
            'time_limit' => 120,
        ]);
    }

    public function testCreateRejectsExternalFormPrepWithoutExternalUrl(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Missing URL'),
            'languages' => Locale::values(),
            'type' => ExamType::ExternalFormPrep->value,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'E_UNPROCESSABLE_ENTITY');
        $this->assertArrayHasKey('external_url.vi', $response->json('data') ?? []);
    }

    public function testCreateRejectsExternalFormPrepMissingLinkForOneSelectedLanguage(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Missing one link'),
            'languages' => ['en', 'ja'],
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => ['en' => 'https://docs.google.com/forms/d/e/example/viewform'],
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en', 'ja']),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('external_url.ja', $response->json('data') ?? []);
    }

    public function testCreateAssignsDifferentLinkPerLanguage(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => ['en' => 'EN title', 'ja' => 'JA title'],
            'languages' => ['en', 'ja'],
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => [
                'en' => 'https://docs.google.com/forms/d/e/en-form/viewform',
                'ja' => 'https://docs.google.com/forms/d/e/ja-form/viewform',
            ],
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en', 'ja']),
        ]);

        $response->assertStatus(200);
        $examId = $response->json('data.id');
        $this->assertExamExternalUrl($examId, 'en', 'https://docs.google.com/forms/d/e/en-form/viewform');
        $this->assertExamExternalUrl($examId, 'ja', 'https://docs.google.com/forms/d/e/ja-form/viewform');
    }

    /**
     * Mirrors testCreateIgnoresContentForALanguageNotSelected in
     * ExamCreateUpdateTest for the `url` format rule: the admin form only
     * renders tabs for selected languages, so a draft value left in an
     * unselected language's tab (e.g. a half-typed URL from before it was
     * deselected) must not be format-validated - there would be no field
     * for the admin to fix a 422 on.
     */
    public function testCreateIgnoresInvalidUrlForALanguageNotSelected(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => ['en' => 'Selected'],
            'languages' => ['en'],
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => [
                'en' => 'https://docs.google.com/forms/d/e/example/viewform',
                'vi' => 'docs.goog',
            ],
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(['en']),
        ]);

        $response->assertStatus(200);
        $examId = $response->json('data.id');
        $this->assertExamExternalUrl($examId, 'en', 'https://docs.google.com/forms/d/e/example/viewform');
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $examId,
            'lang' => 'vi',
            'field' => 'external_url',
        ]);
    }

    public function testCreateRejectsAssessmentExamWithNonUrlExternalUrl(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Assessment exam'),
            'languages' => Locale::values(),
            'type' => ExamType::Assessment->value,
            'external_url' => $this->langPayload('not-a-url'),
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('external_url.vi', $response->json('data') ?? []);
    }

    /**
     * An update payload that omits `type` (legacy API client, or any caller
     * that doesn't resend every field) must not silently downgrade the exam
     * to `assessment` and wipe its `external_url` translations. Doing so
     * would orphan any `pending_external` exam_results already tied to this
     * exam - they'd become unreachable via GetExamResultAction and stuck
     * forever since the expiry job no longer touches non-`in_progress` rows.
     */
    public function testUpdateOmittingTypeKeepsExistingTypeAndPreservesExternalUrl(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $pendingResult = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::PendingExternal,
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('External form prep exam'),
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
        $exam->refresh();
        $this->assertEquals(ExamType::ExternalFormPrep, $exam->type);
        $this->assertEquals(
            'https://docs.google.com/forms/d/e/example/viewform',
            $exam->translation('en', 'external_url')
        );

        $pendingResult->refresh();
        $this->assertEquals(ExamResultStatus::PendingExternal->value, $pendingResult->status);
    }

    /**
     * Validation must derive type-dependent rules from the exam's effective
     * type, not the raw payload: for an `external_form_prep` exam updated by
     * a payload that omits `type`, `min_score`/`time_limit` must not snap
     * back to required (the service overrides them anyway) and an omitted
     * `external_url` must keep the stored URLs rather than being re-required.
     */
    public function testUpdateOmittingTypeAndScoringFieldsAppliesExternalDefaults(): void
    {
        $exam = $this->makeExternalFormPrepExam();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('External form prep exam'),
            'languages' => Locale::values(),
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', [
            'id' => $exam->id,
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'min_score' => 0,
            'retry_limit' => 0,
            'time_limit' => 120,
        ]);
        $this->assertExamExternalUrl($exam->id, 'en', 'https://docs.google.com/forms/d/e/example/viewform');
    }

    /**
     * Omitting `external_url` preserves the stored URLs (above), but an
     * explicitly submitted `null` is different: it must be rejected rather
     * than leave an external exam without a link.
     */
    public function testUpdateOmittingTypeRejectsExplicitNullExternalUrl(): void
    {
        $exam = $this->makeExternalFormPrepExam();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('External form prep exam'),
            'languages' => Locale::values(),
            'external_url' => null,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('external_url.vi', $response->json('data') ?? []);
        $exam->refresh();
        $this->assertEquals(
            'https://docs.google.com/forms/d/e/example/viewform',
            $exam->translation('en', 'external_url')
        );
    }

    /**
     * Switching an `assessment` exam to `external_form_prep` has no stored
     * URL to fall back on, so a payload that omits the key must be rejected -
     * otherwise the update passes and users of the now-external exam receive
     * no link and cannot open the form.
     */
    public function testUpdateTypeChangeToExternalFormPrepRequiresExternalUrl(): void
    {
        $examId = $this->createAssessmentExamViaApi();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$examId}", [
            'title' => $this->langPayload('Now external'),
            'languages' => Locale::values(),
            'type' => ExamType::ExternalFormPrep->value,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('external_url.vi', $response->json('data') ?? []);
        $this->assertDatabaseHas('exams', ['id' => $examId, 'type' => ExamType::Assessment->value]);
        $this->assertNull(Exam::find($examId)->translation('en', 'external_url'));
    }

    /**
     * The happy path of the same switch: submitting the URL together with the
     * new type persists both.
     */
    public function testUpdateTypeChangeToExternalFormPrepWithExternalUrlSucceeds(): void
    {
        $examId = $this->createAssessmentExamViaApi();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$examId}", [
            'title' => $this->langPayload('Now external'),
            'languages' => Locale::values(),
            'type' => ExamType::ExternalFormPrep->value,
            'external_url' => $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', ['id' => $examId, 'type' => ExamType::ExternalFormPrep->value]);
        $this->assertExamExternalUrl($examId, 'en', 'https://docs.google.com/forms/d/e/example/viewform');
    }

    /**
     * The mirror case: switching an external exam back to `assessment` must
     * clear every external_url translation, not just the ones for languages
     * outside the current selection - otherwise the link rows linger unused
     * in the DB and would resurface if the exam is ever switched back to
     * `external_form_prep` without resubmitting a URL.
     */
    public function testUpdateTypeChangeAwayFromExternalFormPrepClearsAllExternalUrlTranslations(): void
    {
        $exam = $this->makeExternalFormPrepExam();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('Now assessment'),
            'languages' => Locale::values(),
            'type' => ExamType::Assessment->value,
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $exam->id,
            'field' => 'external_url',
        ]);
    }

    /**
     * The min_score-vs-question-count check is meaningless for
     * `external_form_prep` (min_score is forced to 0) and must stay skipped
     * when the payload omits `type` on an exam of that type.
     */
    public function testUpdateOmittingTypeSkipsMinScoreQuestionCountValidation(): void
    {
        $exam = $this->makeExternalFormPrepExam();

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('External form prep exam'),
            'languages' => Locale::values(),
            'min_score' => 99,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'min_score' => 0]);
    }

    /**
     * Unlike omitting `type` (handled above), an admin can explicitly pick
     * "Knowledge assessment" in the dropdown for an exam that already has
     * `pending_external` results and save - `type` is present in the
     * payload here, so the array_key_exists guard doesn't apply. Once
     * exam_results exist, changing type at all must be rejected: switching
     * away from external_form_prep would orphan those rows
     * (GetExamResultAction 404s, ExamResultCalculation never touches
     * non-in_progress rows, and the admin override button disappears from
     * the UI with no way back).
     */
    public function testUpdateRejectsExplicitTypeChangeWhenExamResultsExist(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::PendingExternal,
        ]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/admin/exams/{$exam->id}", [
            'title' => $this->langPayload('External form prep exam'),
            'languages' => Locale::values(),
            'type' => ExamType::Assessment->value,
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('type', $response->json('data') ?? []);
        $exam->refresh();
        $this->assertEquals(ExamType::ExternalFormPrep, $exam->type);
    }

    public function testAdminOverrideCompletesExternalStepAndAppearsInPendingExamsCheck(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $employeeRole = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $employee->addRole($employeeRole);
        // An employee without a workplace is part of no exam's audience, so
        // the exam would never show up as pending for them.
        $employee->workplaces()->attach(
            Workplace::create([
                'name' => 'Tokyo',
                'address' => '1-1-1',
                'latitude' => '35.0',
                'longitude' => '139.0',
            ])->id
        );

        $result = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::PendingExternal,
        ]);

        $before = $this->actingAs($employee, 'api')->getJson('/auth/check');
        $this->assertContains($exam->id, collect($before->json('data.pending_exams'))->pluck('id')->all());

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/exam-results/{$result->id}/complete-external-override"
        );

        $response->assertStatus(200)->assertJsonPath('data.status', ExamResultStatus::Completed->value);
        $result->refresh();
        $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
        $this->assertNotNull($result->external_completed_at);
        $this->assertEquals($this->admin->id, $result->external_completed_by);

        $after = $this->actingAs($employee, 'api')->getJson('/auth/check');
        $after->assertStatus(200)->assertJsonPath('data.pending_exams', []);
    }

    /**
     * Re-review can leave several pending_external attempts for the same
     * user+exam (reachable through the real start/submit flow — proven by
     * ExternalFormPrepExamTest::testCompleteExternalClosesAllPendingAttemptsForSameExam).
     * The exam has one external form, so an admin override must close all of
     * them, not just the targeted row — otherwise the siblings stay pending
     * forever as phantom stragglers in the exam-result list.
     */
    public function testAdminOverrideCompletesSiblingPendingAttempts(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);

        $olderPending = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
            'completed_at' => now()->subHour(),
            'status' => ExamResultStatus::PendingExternal,
        ]);
        $newerPending = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::PendingExternal,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/exam-results/{$newerPending->id}/complete-external-override"
        );

        $response->assertStatus(200);
        foreach ([$olderPending, $newerPending] as $result) {
            $result->refresh();
            $this->assertEquals(ExamResultStatus::Completed->value, $result->status);
            $this->assertNotNull($result->external_completed_at);
            $this->assertEquals($this->admin->id, $result->external_completed_by);
        }
    }

    /**
     * complete-external-override is a write action (it force-completes a
     * result), so it must be gated by exam.edit, not exam.result.list. A
     * role granted only read access to exam results (e.g. leader/HR
     * lookup) must not be able to force-complete another user's exam.
     */
    public function testAdminOverrideRejectsRoleWithOnlyExamResultListPermission(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $result = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::PendingExternal,
        ]);

        $viewerRole = Role::firstOrCreate(['name' => 'exam_result_viewer'], ['display_name' => 'Exam Result Viewer']);
        $viewerRole
            ->permissions()
            ->syncWithoutDetaching(
                Permission::query()->where('name', PermissionName::ExamResultList->value)->pluck('id')->all()
            );
        $viewer = User::factory()->create();
        $viewer->addRole($viewerRole);

        $response = $this->actingAs($viewer, 'api')->postJson(
            "/admin/exam-results/{$result->id}/complete-external-override"
        );

        $response->assertStatus(403);
        $result->refresh();
        $this->assertEquals(ExamResultStatus::PendingExternal->value, $result->status);
    }

    public function testAdminOverrideRejectsAssessmentTypeExam(): void
    {
        $exam = Exam::create(['is_required' => false, 'retry_limit' => 0, 'min_score' => 1, 'time_limit' => 30]);
        $exam->syncTranslations($this->langPayload('Assessment exam'));
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $result = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'completed_at' => now(),
            'status' => ExamResultStatus::Completed,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/exam-results/{$result->id}/complete-external-override"
        );

        $response->assertStatus(422);
    }

    /**
     * A row still `in_progress` (user started the session but never
     * submitted, so there is no external form confirmation to override yet)
     * must not be force-completed - that would fabricate a "completed"
     * result with no submitted answers and let GetExamResultAction reveal
     * correct answers for a session the user never finished.
     */
    public function testAdminOverrideRejectsInProgressResult(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $result = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 0,
            'total_questions' => 1,
            'correct_answers' => 0,
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'status' => ExamResultStatus::InProgress,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/exam-results/{$result->id}/complete-external-override"
        );

        $response->assertStatus(422);
        $result->refresh();
        $this->assertEquals(ExamResultStatus::InProgress->value, $result->status);
        $this->assertNull($result->external_completed_at);
    }

    /**
     * Calling the override twice (or once after the user already completed
     * the external step themselves) must not overwrite the original
     * external_completed_at/by audit trail.
     */
    public function testAdminOverrideDoesNotOverwriteAlreadyCompletedResult(): void
    {
        $exam = $this->makeExternalFormPrepExam();
        $employee = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $originalCompletedAt = now()->subDay();
        $result = ExamResult::create([
            'user_id' => $employee->id,
            'exam_id' => $exam->id,
            'score' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'started_at' => now()->subDay()->subMinute(),
            'expires_at' => now()->subDay()->addHour(),
            'completed_at' => now()->subDay(),
            'status' => ExamResultStatus::Completed,
            'external_completed_at' => $originalCompletedAt,
            'external_completed_by' => $employee->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/admin/exam-results/{$result->id}/complete-external-override"
        );

        $response->assertStatus(422);
        $result->refresh();
        $this->assertEquals($originalCompletedAt->format('Y-m-d H:i:s'), $result->external_completed_at);
        $this->assertEquals($employee->id, $result->external_completed_by);
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }

    private function questionsPayload(array $languages = []): array
    {
        $languages = $languages ?: Locale::values();

        return [
            [
                'id' => null,
                'content' => array_fill_keys($languages, 'Q1?'),
                'options' => [
                    ['id' => null, 'content' => array_fill_keys($languages, 'A'), 'is_correct' => true],
                    ['id' => null, 'content' => array_fill_keys($languages, 'B'), 'is_correct' => false],
                ],
            ],
        ];
    }

    /**
     * Creates an `assessment` exam through the real create endpoint so the
     * type-switch tests start from a state the flow can actually reach.
     */
    private function createAssessmentExamViaApi(): int
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/exams', [
            'title' => $this->langPayload('Assessment exam'),
            'languages' => Locale::values(),
            'type' => ExamType::Assessment->value,
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'workplaces' => [],
            'documents' => [],
            'questions' => $this->questionsPayload(),
        ]);
        $response->assertStatus(200);

        return $response->json('data.id');
    }

    private function makeExternalFormPrepExam(array $overrides = []): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'type' => ExamType::ExternalFormPrep->value,
                    'is_required' => true,
                    'retry_limit' => 0,
                    'min_score' => 0,
                    'time_limit' => 120,
                ],
                $overrides
            )
        );
        $exam->syncTranslations($this->langPayload('External form prep exam'));
        $exam->syncTranslations(
            $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
            'external_url'
        );
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations($this->langPayload('A'));

        return $exam;
    }

    private function assertExamExternalUrl(int $examId, string $lang, string $expected): void
    {
        $this->assertDatabaseHas('exam_translations', [
            'translatable_type' => Exam::class,
            'translatable_id' => $examId,
            'lang' => $lang,
            'field' => 'external_url',
            'value' => $expected,
        ]);
    }
}
