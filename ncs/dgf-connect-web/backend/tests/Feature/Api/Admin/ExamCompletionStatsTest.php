<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExamType;
use App\Enums\Locale;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class ExamCompletionStatsTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(
            ['name' => PermissionName::ExamList->value],
            ['display_name' => PermissionName::ExamList->value]
        );
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role
            ->permissions()
            ->syncWithoutDetaching(
                Permission::query()->where('name', PermissionName::ExamList->value)->pluck('id')->all()
            );

        $this->admin = User::factory()->create();
        $this->admin->addRole($role);
    }

    /**
     * Eligibility and "completed" state are reached through the real
     * start -> submit endpoints, per the project's Test Design Rules,
     * rather than inserting exam_results rows directly.
     */
    public function testAssessmentExamCountsUsersPassingMinScoreWithinWorkplaceScope(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 1], [$workplace->id]);
        $question = $this->makeQuestionWithOptions($exam);

        $passing = $this->makeEligibleUser($workplace);
        $failing = $this->makeEligibleUser($workplace);
        $untouched = $this->makeEligibleUser($workplace);
        // Approved but not assigned to this workplace: not eligible.
        $this->makeEligibleUser($this->makeWorkplace());

        $this->takeExam($passing, $exam, $question, correct: true);
        $this->takeExam($failing, $exam, $question, correct: false);
        // $untouched never attempts the exam.

        $stats = $this->statsFor($exam);

        $this->assertEquals(3, $stats['eligible_count']);
        $this->assertEquals(1, $stats['completed_count']);
        $this->assertEquals(2, $stats['pending_count']);
        $this->assertEquals(0.3333, $stats['completion_rate']);
    }

    /**
     * Surprising cell: min_score = 0 is a valid assessment configuration, and
     * StartOrResumeExamAction creates every new attempt with score = 0 and
     * status = in_progress. A score-only check would satisfy `0 >= 0` and
     * count a user who merely opened the exam as completed.
     */
    public function testInProgressAttemptWithMinScoreZeroIsNotCountedAsCompleted(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 0], [$workplace->id]);
        $this->makeQuestionWithOptions($exam);

        $user = $this->makeEligibleUser($workplace);
        $this->actingAs($user, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertStatus(200);

        $stats = $this->statsFor($exam);

        $this->assertEquals(1, $stats['eligible_count']);
        $this->assertEquals(0, $stats['completed_count']);
        $this->assertEquals(1, $stats['pending_count']);
    }

    public function testExternalFormPrepExamOnlyCountsConfirmedCompletions(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeExternalFormPrepExam([$workplace->id]);
        $question = $this->makeQuestionWithOptions($exam);

        $confirmed = $this->makeEligibleUser($workplace);
        $submittedOnly = $this->makeEligibleUser($workplace);

        $confirmedResultId = $this->startAndSubmit($confirmed, $exam, $question);
        $this->actingAs($confirmed, 'api')->postJson(
            "/user/exams/{$exam->id}/results/{$confirmedResultId}/complete-external"
        );
        // Submitted but never confirms the external form: stays pending_external.
        $this->startAndSubmit($submittedOnly, $exam, $question);

        $stats = $this->statsFor($exam);

        $this->assertEquals(2, $stats['eligible_count']);
        $this->assertEquals(1, $stats['completed_count']);
        $this->assertEquals(1, $stats['pending_count']);
    }

    public function testExamWithoutWorkplaceScopeCountsApprovedUsersOfEveryWorkplace(): void
    {
        $exam = $this->makeAssessmentExam(['min_score' => 0], []);

        $this->makeEligibleUser($this->makeWorkplace());
        $this->makeEligibleUser($this->makeWorkplace());
        // Belongs to no workplace, so part of no exam's audience.
        $this->makeEligibleUser(null);
        User::factory()->create(['paper_status' => UserPaperStatus::Pending->value]);
        User::factory()->create(['paper_status' => UserPaperStatus::Approved->value, 'is_disabled' => true]);

        $stats = $this->statsFor($exam);

        $this->assertEquals(2, $stats['eligible_count']);
    }

    /**
     * A workplace-less user is part of no exam's audience at all - not even a
     * global (unscoped) required one. ApplyExamAudienceScopeAction matches
     * nothing for them, so canUserViewExam() denies the start endpoint,
     * GetPendingRequiredExamsAction skips them and
     * ExamNotificationService::getRecipientsForExam() never notifies them.
     * Counting them as eligible would leave the exam permanently pending for
     * someone (in practice a global admin) who cannot open it.
     *
     * Reached through the real endpoints rather than by asserting the count
     * alone, so the stat and the gate cannot drift apart unnoticed.
     */
    public function testUserWithoutAnyWorkplaceIsExcludedFromGlobalRequiredExamStats(): void
    {
        $exam = $this->makeAssessmentExam(['min_score' => 0], []);
        $question = $this->makeQuestionWithOptions($exam);

        $withWorkplace = $this->makeEligibleUser($this->makeWorkplace());
        $this->takeExam($withWorkplace, $exam, $question, correct: true);

        $withoutWorkplace = $this->makeEligibleUser(null);
        $this->actingAs($withoutWorkplace, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertStatus(403);

        $stats = $this->statsFor($exam);

        $this->assertEquals(1, $stats['eligible_count']);
        $this->assertEquals(1, $stats['completed_count']);
        $this->assertEquals(0, $stats['pending_count']);
    }

    /**
     * The optional case reaches the same conclusion by the same rule - the
     * exam list is empty for such a user and the start endpoint denies them -
     * so is_required makes no difference to who is counted.
     */
    public function testUserWithoutAnyWorkplaceIsExcludedFromGlobalOptionalExamStats(): void
    {
        $exam = $this->makeAssessmentExam(['min_score' => 0, 'is_required' => false], []);
        $question = $this->makeQuestionWithOptions($exam);

        $withWorkplace = $this->makeEligibleUser($this->makeWorkplace());
        $this->takeExam($withWorkplace, $exam, $question, correct: true);

        $withoutWorkplace = $this->makeEligibleUser(null);
        $this->actingAs($withoutWorkplace, 'api')
            ->getJson('/user/exams')
            ->assertStatus(200)
            ->assertJsonPath('data.data', []);
        $this->actingAs($withoutWorkplace, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertStatus(403);

        $stats = $this->statsFor($exam);

        $this->assertEquals(1, $stats['eligible_count']);
        $this->assertEquals(1, $stats['completed_count']);
        $this->assertEquals(0, $stats['pending_count']);
    }

    /**
     * A scoped exam that names employees is answerable only by them, so the
     * other members of its workplaces must not be counted as eligible -
     * otherwise pending_count never reaches zero no matter how many of the
     * named employees finish.
     */
    public function testScopedExamWithNamedEmployeesCountsOnlyThoseEmployees(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 0], [$workplace->id]);
        $question = $this->makeQuestionWithOptions($exam);

        $named = $this->makeEligibleUser($workplace);
        $alsoNamed = $this->makeEligibleUser($workplace);
        $notNamed = $this->makeEligibleUser($workplace);
        $exam->targetedUsers()->sync([$named->id, $alsoNamed->id]);

        $this->takeExam($named, $exam, $question, correct: true);
        $this->actingAs($notNamed, 'api')
            ->postJson("/user/exams/{$exam->id}/start")
            ->assertStatus(403);

        $stats = $this->statsFor($exam);

        $this->assertEquals(2, $stats['eligible_count']);
        $this->assertEquals(1, $stats['completed_count']);
        $this->assertEquals(1, $stats['pending_count']);
    }

    /**
     * A named employee who has since left every targeted workplace is out of
     * the audience - the link alone grants nothing, exactly as the audience
     * rule re-derives membership at query time.
     */
    public function testNamedEmployeeWhoLeftTheWorkplaceIsNotCountedAsEligible(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 0], [$workplace->id]);

        $stillMember = $this->makeEligibleUser($workplace);
        $left = $this->makeEligibleUser($workplace);
        $exam->targetedUsers()->sync([$stillMember->id, $left->id]);
        $left->workplaces()->sync([]);

        $stats = $this->statsFor($exam);

        $this->assertEquals(1, $stats['eligible_count']);
    }

    /**
     * exam_workplace cascades on workplace deletion while exam_user does not,
     * so a targeted exam can end up with named employees and no workplace.
     * That is not a legacy "everyone" exam - its audience is empty, and a
     * result left over from before must not push pending_count negative.
     */
    public function testTargetedExamThatLostItsLastWorkplaceHasAnEmptyAudience(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 0], [$workplace->id]);
        $question = $this->makeQuestionWithOptions($exam);

        $named = $this->makeEligibleUser($workplace);
        $exam->targetedUsers()->sync([$named->id]);
        $this->takeExam($named, $exam, $question, correct: true);

        $exam->workplaces()->sync([]);

        $stats = $this->statsFor($exam);

        $this->assertEquals(0, $stats['eligible_count']);
        $this->assertEquals(0, $stats['completed_count']);
        $this->assertEquals(0, $stats['pending_count']);
    }

    /**
     * The batching in forExams() splits exams by audience mode (scoped /
     * global / named-but-workplace-less) and type (assessment/external),
     * aggregates each group by exam_id, then re-attaches results per exam. A
     * single-exam test can't catch a bug where results from one exam bleed
     * into another's row, or where an exam with no eligible users or no
     * results is dropped from the aggregate entirely — this exercises every
     * mode on one page.
     *
     * Crucially the scoped group holds both targeted and untargeted exams
     * here: restrictToNamedEmployees() narrows an audience with a condition
     * correlated on exam_id, and a version correlated on the wrong exam (or
     * not at all) only shows up when both kinds share one aggregate — it would
     * either widen the targeted exam to its whole workplace or empty the
     * untargeted ones.
     */
    public function testStatsAreCorrectPerExamWhenMultipleScopesAndTypesShareAPage(): void
    {
        // Each scoped exam gets its own workplace so eligibility — which is
        // workplace membership, not exam-specific — can't leak between them.
        $scopedAssessment = $this->makeAssessmentExam(['min_score' => 1], [$this->makeWorkplace()->id]);
        $scopedWorkplace = $scopedAssessment->workplaces->first();
        $scopedAssessmentQuestion = $this->makeQuestionWithOptions($scopedAssessment);
        $scopedPassing = $this->makeEligibleUser($scopedWorkplace);
        $scopedFailing = $this->makeEligibleUser($scopedWorkplace);
        $this->takeExam($scopedPassing, $scopedAssessment, $scopedAssessmentQuestion, correct: true);
        $this->takeExam($scopedFailing, $scopedAssessment, $scopedAssessmentQuestion, correct: false);

        $globalAssessment = $this->makeAssessmentExam(['min_score' => 0], []);
        $globalAssessmentQuestion = $this->makeQuestionWithOptions($globalAssessment);
        $globalUser = $this->makeEligibleUser($this->makeWorkplace());
        $this->takeExam($globalUser, $globalAssessment, $globalAssessmentQuestion, correct: true);
        // Approved but never assigned a workplace: part of no exam's audience,
        // so excluded from both global exams below.
        $this->makeEligibleUser(null);

        $globalOptional = $this->makeAssessmentExam(['min_score' => 0, 'is_required' => false], []);
        $this->makeQuestionWithOptions($globalOptional);

        $scopedExternal = $this->makeExternalFormPrepExam([$this->makeWorkplace()->id]);
        $scopedExternalWorkplace = $scopedExternal->workplaces->first();
        $scopedExternalQuestion = $this->makeQuestionWithOptions($scopedExternal);
        $confirmedExternalUser = $this->makeEligibleUser($scopedExternalWorkplace);
        $confirmedResultId = $this->startAndSubmit($confirmedExternalUser, $scopedExternal, $scopedExternalQuestion);
        $this->actingAs($confirmedExternalUser, 'api')->postJson(
            "/user/exams/{$scopedExternal->id}/results/{$confirmedResultId}/complete-external"
        );

        // Scoped to a workplace with nobody in it: eligible_count and
        // completed_count must both stay 0, not disappear from the
        // aggregate or fall back to another exam's counts. (Global
        // eligibility is shared across every unscoped exam on the page, so
        // this case can only be produced with a scope.)
        $emptyWorkplaceExternal = $this->makeExternalFormPrepExam([$this->makeWorkplace()->id]);
        $this->makeQuestionWithOptions($emptyWorkplaceExternal);

        // Has an eligible user but no exam_results row yet: must still
        // report 0 completed rather than being dropped from the aggregate.
        $emptyScopedAssessment = $this->makeAssessmentExam(['min_score' => 1], [$this->makeWorkplace()->id]);
        $this->makeQuestionWithOptions($emptyScopedAssessment);
        $this->makeEligibleUser($emptyScopedAssessment->workplaces->first());

        // Scoped *and* targeted, sharing the scoped aggregate with the
        // untargeted exams above: only the two named employees are eligible,
        // while the third member of the same workplace is denied the exam
        // outright.
        $targetedScoped = $this->makeAssessmentExam(['min_score' => 1], [$this->makeWorkplace()->id]);
        $targetedScopedQuestion = $this->makeQuestionWithOptions($targetedScoped);
        $targetedWorkplace = $targetedScoped->workplaces->first();
        $namedPassing = $this->makeEligibleUser($targetedWorkplace);
        $namedPending = $this->makeEligibleUser($targetedWorkplace);
        $notNamed = $this->makeEligibleUser($targetedWorkplace);
        $targetedScoped->targetedUsers()->sync([$namedPassing->id, $namedPending->id]);
        $this->takeExam($namedPassing, $targetedScoped, $targetedScopedQuestion, correct: true);
        $this->actingAs($notNamed, 'api')
            ->postJson("/user/exams/{$targetedScoped->id}/start")
            ->assertStatus(403);

        // Named employees but no workplace left (exam_workplace cascades on
        // workplace removal, exam_user does not): an empty audience carrying a
        // real completion from before, on a page where other exams do count
        // completions.
        $targetedWithoutWorkplace = $this->makeAssessmentExam(['min_score' => 1], [$this->makeWorkplace()->id]);
        $orphanQuestion = $this->makeQuestionWithOptions($targetedWithoutWorkplace);
        $orphanNamed = $this->makeEligibleUser($targetedWithoutWorkplace->workplaces->first());
        $targetedWithoutWorkplace->targetedUsers()->sync([$orphanNamed->id]);
        $this->takeExam($orphanNamed, $targetedWithoutWorkplace, $orphanQuestion, correct: true);
        $targetedWithoutWorkplace->workplaces()->sync([]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams');
        $response->assertStatus(200);
        $rows = collect($response->json('data.data'))->keyBy('id');

        $scopedStats = $rows[$scopedAssessment->id]['completion_stats'];
        $this->assertEquals(2, $scopedStats['eligible_count']);
        $this->assertEquals(1, $scopedStats['completed_count']);

        // Global eligibility is every approved, non-disabled user who belongs
        // to at least one workplace — not just this exam's completers — so it
        // picks up 9 of the 10 users created above (2 scoped-assessment +
        // 1 scoped-external + 1 empty-scoped-assessment + 3 targeted-scoped +
        // 1 named-but-workplace-less + globalUser), and drops the
        // workplace-less one. Being named on another exam narrows nothing
        // here.
        $globalStats = $rows[$globalAssessment->id]['completion_stats'];
        $this->assertEquals(9, $globalStats['eligible_count']);
        $this->assertEquals(1, $globalStats['completed_count']);

        // is_required makes no difference to who is in a global exam's
        // audience, so the optional one counts the same population.
        $globalOptionalStats = $rows[$globalOptional->id]['completion_stats'];
        $this->assertEquals(9, $globalOptionalStats['eligible_count']);
        $this->assertEquals(0, $globalOptionalStats['completed_count']);

        $scopedExternalStats = $rows[$scopedExternal->id]['completion_stats'];
        $this->assertEquals(1, $scopedExternalStats['eligible_count']);
        $this->assertEquals(1, $scopedExternalStats['completed_count']);

        $emptyWorkplaceExternalStats = $rows[$emptyWorkplaceExternal->id]['completion_stats'];
        $this->assertEquals(0, $emptyWorkplaceExternalStats['eligible_count']);
        $this->assertEquals(0, $emptyWorkplaceExternalStats['completed_count']);

        $emptyScopedStats = $rows[$emptyScopedAssessment->id]['completion_stats'];
        $this->assertEquals(1, $emptyScopedStats['eligible_count']);
        $this->assertEquals(0, $emptyScopedStats['completed_count']);

        // Named employees only: the third member of the same workplace is out,
        // even though the untargeted scoped exams on this page do count every
        // member of theirs.
        $targetedScopedStats = $rows[$targetedScoped->id]['completion_stats'];
        $this->assertEquals(2, $targetedScopedStats['eligible_count']);
        $this->assertEquals(1, $targetedScopedStats['completed_count']);
        $this->assertEquals(1, $targetedScopedStats['pending_count']);

        $orphanStats = $rows[$targetedWithoutWorkplace->id]['completion_stats'];
        $this->assertEquals(0, $orphanStats['eligible_count']);
        $this->assertEquals(0, $orphanStats['completed_count']);
        $this->assertEquals(0, $orphanStats['pending_count']);
    }

    /**
     * forExams() resolves a whole page with a fixed number of aggregate
     * queries. It used to call loadMissing() on each Exam, which fired one
     * targetedUsers query per row on every admin list load (15 by default, up
     * to 100 on request) just to learn whether the relation was empty.
     *
     * Asserting the two page sizes cost the same pins the property itself —
     * independence from row count — rather than a magic total that any
     * unrelated eager load would invalidate. Every exam here is the same shape
     * (scoped + targeted), so the audience groups and their aggregates are
     * identical between the runs and only the row count differs.
     */
    public function testListResolvesStatsWithoutAQueryPerExam(): void
    {
        // Warm up whatever is cached per process (permissions, schema) so the
        // two measured requests start from the same state.
        $this->makeTargetedScopedExam();
        $this->actingAs($this->admin, 'api')->getJson('/admin/exams')->assertStatus(200);

        $onePageRow = $this->countQueriesForExamList();

        foreach (range(1, 4) as $ignored) {
            $this->makeTargetedScopedExam();
        }

        $this->assertSame($onePageRow, $this->countQueriesForExamList());
    }

    public function testListResolvesGlobalAssessmentStatsWithoutAQueryPerExam(): void
    {
        $this->makeEligibleUser($this->makeWorkplace());
        $this->makeAssessmentExam(['min_score' => 1], []);
        $this->actingAs($this->admin, 'api')->getJson('/admin/exams')->assertStatus(200);

        $onePageRow = $this->countQueriesForExamList();

        foreach (range(1, 4) as $ignored) {
            $this->makeAssessmentExam(['min_score' => 1], []);
        }

        $this->assertSame($onePageRow, $this->countQueriesForExamList());
    }

    public function testGlobalAssessmentExamsOnOnePageKeepAPasserWhoIsNoLongerActiveOnlyOnTheirOwnExam(): void
    {
        $passedExam = $this->makeAssessmentExam(['min_score' => 1], []);
        $otherExam = $this->makeAssessmentExam(['min_score' => 1], []);
        $question = $this->makeQuestionWithOptions($passedExam);
        $formerlyActive = $this->makeEligibleUser($this->makeWorkplace());
        $this->takeExam($formerlyActive, $passedExam, $question, correct: true);

        $this->travel(20)->days();
        $this->makeEligibleUser($this->makeWorkplace());

        $passedStats = $this->statsFor($passedExam);
        $otherStats = $this->statsFor($otherExam);

        $this->assertEquals(2, $passedStats['eligible_count']);
        $this->assertEquals(1, $passedStats['completed_count']);
        $this->assertEquals(1, $otherStats['eligible_count']);
        $this->assertEquals(0, $otherStats['completed_count']);
    }

    public function testExamWithNoEligibleUsersHasZeroCompletionRate(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 1], [$workplace->id]);

        $stats = $this->statsFor($exam);

        $this->assertEquals(0, $stats['eligible_count']);
        $this->assertEquals(0, $stats['completed_count']);
        $this->assertEquals(0, $stats['pending_count']);
        $this->assertEquals(0.0, $stats['completion_rate']);
    }

    /**
     * Surprising cell: a user completes the exam, then is later disabled.
     * They must drop out of both eligible_count and completed_count, not
     * just eligible_count (which would otherwise make completed_count
     * exceed eligible_count).
     */
    public function testUserDisabledAfterCompletingIsExcludedFromBothCounts(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 1], [$workplace->id]);
        $question = $this->makeQuestionWithOptions($exam);

        $user = $this->makeEligibleUser($workplace);
        $this->takeExam($user, $exam, $question, correct: true);

        $user->update(['is_disabled' => true]);

        $stats = $this->statsFor($exam);

        $this->assertEquals(0, $stats['eligible_count']);
        $this->assertEquals(0, $stats['completed_count']);
    }

    public function testOptionalExamHasCompletionStatsJustLikeRequiredExam(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 1, 'is_required' => false], [$workplace->id]);
        $question = $this->makeQuestionWithOptions($exam);

        $user = $this->makeEligibleUser($workplace);
        $this->takeExam($user, $exam, $question, correct: true);

        $stats = $this->statsFor($exam);

        $this->assertEquals(1, $stats['eligible_count']);
        $this->assertEquals(1, $stats['completed_count']);
    }

    private function countQueriesForExamList(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin, 'api')->getJson('/admin/exams')->assertStatus(200);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function makeTargetedScopedExam(): void
    {
        $workplace = $this->makeWorkplace();
        $exam = $this->makeAssessmentExam(['min_score' => 1], [$workplace->id]);
        $exam->targetedUsers()->sync([$this->makeEligibleUser($workplace)->id]);
    }

    private function statsFor(Exam $exam): array
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/admin/exams');
        $response->assertStatus(200);

        $row = collect($response->json('data.data'))->firstWhere('id', $exam->id);
        $this->assertNotNull($row, 'Exam not found in admin exam list response.');

        return $row['completion_stats'];
    }

    private function makeWorkplace(): Workplace
    {
        return Workplace::create([
            'name' => 'Workplace ' . uniqid(),
            'address' => '1-1-1',
            'latitude' => '35.0',
            'longitude' => '139.0',
        ]);
    }

    private function makeEligibleUser(?Workplace $workplace): User
    {
        $user = User::factory()->create(['paper_status' => UserPaperStatus::Approved->value]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $user->addRole($role);
        if ($workplace !== null) {
            $user->workplaces()->attach($workplace->id);
            $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));
        }

        return $user;
    }

    private function makeAssessmentExam(array $overrides, array $workplaceIds): Exam
    {
        $exam = Exam::create(
            array_merge(
                [
                    'is_required' => true,
                    'retry_limit' => 3,
                    'min_score' => 1,
                    'time_limit' => 30,
                    'start_at' => null,
                    'end_at' => null,
                ],
                $overrides
            )
        );
        $exam->syncTranslations($this->langPayload('Assessment exam'));
        $exam->workplaces()->sync($workplaceIds);

        return $exam;
    }

    private function makeExternalFormPrepExam(array $workplaceIds): Exam
    {
        $exam = Exam::create([
            'type' => ExamType::ExternalFormPrep->value,
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 0,
            'time_limit' => 120,
            'start_at' => null,
            'end_at' => null,
        ]);
        $exam->syncTranslations($this->langPayload('External form prep exam'));
        $exam->syncTranslations(
            $this->langPayload('https://docs.google.com/forms/d/e/example/viewform'),
            'external_url'
        );
        $exam->workplaces()->sync($workplaceIds);

        return $exam;
    }

    private function makeQuestionWithOptions(Exam $exam): Question
    {
        $question = Question::create(['exam_id' => $exam->id]);
        $question->syncTranslations($this->langPayload('Q1?'));
        $correct = $question->options()->create(['is_correct' => true]);
        $correct->syncTranslations($this->langPayload('A'));
        $wrong = $question->options()->create(['is_correct' => false]);
        $wrong->syncTranslations($this->langPayload('B'));

        return $question;
    }

    private function takeExam(User $user, Exam $exam, Question $question, bool $correct): void
    {
        $this->startAndSubmit($user, $exam, $question, $correct);
    }

    private function startAndSubmit(User $user, Exam $exam, Question $question, bool $correct = false): int
    {
        $startResponse = $this->actingAs($user, 'api')->postJson("/user/exams/{$exam->id}/start");
        $startResponse->assertStatus(200);
        $resultId = $startResponse->json('data.result.id');

        $optionId = $question->options()->where('is_correct', $correct)->first()->id;

        $submitResponse = $this->actingAs($user, 'api')->postJson("/user/exams/{$exam->id}/submit", [
            'answers' => [['question_id' => $question->id, 'option_id' => $optionId]],
            'is_final' => true,
        ]);
        $submitResponse->assertStatus(200);

        return $resultId;
    }

    private function langPayload(string $value): array
    {
        return array_fill_keys(Locale::values(), $value);
    }
}
