<?php

namespace Tests\Unit\Actions\Common\Exam;

use App\Actions\Common\Exam\ApplyExamAudienceScopeAction;
use App\Enums\Locale;
use App\Models\Exam;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

/**
 * The visibility matrix. Every decision point on the employee -> exams side
 * runs through this action, so a hole here is a hole in all three of them.
 */
class ApplyExamAudienceScopeActionTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private ApplyExamAudienceScopeAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ApplyExamAudienceScopeAction::class);
    }

    public function testLegacyExamWithoutWorkplacesQualifiesEmployeeWithAWorkplace(): void
    {
        $workplace = $this->makeWorkplace('A');
        $user = $this->makeUser([$workplace]);
        $exam = $this->makeExam();

        $this->assertVisible($user, $exam);
    }

    public function testEmployeeWithoutWorkplaceQualifiesForNothingIncludingLegacyExam(): void
    {
        $workplace = $this->makeWorkplace('A');
        $user = $this->makeUser([]);

        $legacyExam = $this->makeExam();
        $workplaceExam = $this->makeExam([$workplace]);
        $namedExam = $this->makeExam([$workplace], [$user]);

        $this->assertNotVisible($user, $legacyExam);
        $this->assertNotVisible($user, $workplaceExam);
        $this->assertNotVisible($user, $namedExam);
    }

    /**
     * exam_workplace cascades on workplace deletion, exam_user does not - so
     * a targeted exam can end up with named employees and no workplace. That
     * is an emptied audience, not the legacy "no targeting at all" exam: it
     * must not open to everybody who happens to have a workplace.
     */
    public function testTargetedExamThatLostItsLastWorkplaceQualifiesNobody(): void
    {
        $deleted = $this->makeWorkplace('A');
        $survivor = $this->makeWorkplace('B');
        // Both keep a workplace after the deletion, so the "employee without a
        // workplace" rule cannot be what hides the exam from them.
        $named = $this->makeUser([$deleted, $survivor]);
        $bystander = $this->makeUser([$survivor]);

        $exam = $this->makeExam([$deleted], [$named]);
        $this->assertVisible($named, $exam);

        $this->clearExamShifts($deleted);
        $deleted->delete();

        $this->assertDatabaseHas('exam_user', ['exam_id' => $exam->id, 'user_id' => $named->id]);
        $this->assertDatabaseMissing('exam_workplace', ['exam_id' => $exam->id]);

        $this->assertNotVisible($named, $exam);
        $this->assertNotVisible($bystander, $exam);
    }

    public function testAllMembersModeQualifiesEveryMemberOfTheTargetedWorkplace(): void
    {
        $workplace = $this->makeWorkplace('A');
        $memberOne = $this->makeUser([$workplace]);
        $memberTwo = $this->makeUser([$workplace]);
        $outsider = $this->makeUser([$this->makeWorkplace('B')]);

        $exam = $this->makeExam([$workplace]);

        $this->assertVisible($memberOne, $exam);
        $this->assertVisible($memberTwo, $exam);
        $this->assertNotVisible($outsider, $exam);
    }

    public function testNamedMembersModeQualifiesOnlyNamedMembers(): void
    {
        $workplace = $this->makeWorkplace('A');
        $named = $this->makeUser([$workplace]);
        $notNamed = $this->makeUser([$workplace]);

        $exam = $this->makeExam([$workplace], [$named]);

        $this->assertVisible($named, $exam);
        $this->assertNotVisible($notNamed, $exam);
    }

    public function testMultiWorkplaceExamQualifiesMemberOfAnyOneOfItsWorkplaces(): void
    {
        $workplaceA = $this->makeWorkplace('A');
        $workplaceB = $this->makeWorkplace('B');
        $memberOfB = $this->makeUser([$workplaceB]);

        $exam = $this->makeExam([$workplaceA, $workplaceB]);

        $this->assertVisible($memberOfB, $exam);
    }

    public function testNamedEmployeeWhoLeftEveryTargetedWorkplaceDoesNotQualify(): void
    {
        $workplace = $this->makeWorkplace('A');
        $otherWorkplace = $this->makeWorkplace('B');
        $user = $this->makeUser([$workplace]);

        $exam = $this->makeExam([$workplace], [$user]);
        $this->assertVisible($user, $exam);

        // The link survives; membership does not. The rule re-derives
        // membership at query time, so the stale link grants nothing.
        $user->workplaces()->sync([$otherWorkplace->id]);
        $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));
        $user->refresh();

        $this->assertNotVisible($user, $exam);
        $this->assertDatabaseHas('exam_user', ['exam_id' => $exam->id, 'user_id' => $user->id]);
    }

    public function testNamedEmployeeOfAWorkplaceTheExamDoesNotTargetDoesNotQualify(): void
    {
        $examWorkplace = $this->makeWorkplace('A');
        $otherWorkplace = $this->makeWorkplace('B');
        $user = $this->makeUser([$otherWorkplace]);

        $exam = $this->makeExam([$examWorkplace], [$user]);

        $this->assertNotVisible($user, $exam);
    }

    public function testMemberOfOneTargetedWorkplaceIsExcludedWhenSomebodyElseIsNamed(): void
    {
        $workplaceA = $this->makeWorkplace('A');
        $workplaceB = $this->makeWorkplace('B');
        $namedInA = $this->makeUser([$workplaceA]);
        $memberOfB = $this->makeUser([$workplaceB]);

        // Exam-wide targeting (DDR 1): naming anyone restricts the whole
        // exam, so workplace B receives nothing.
        $exam = $this->makeExam([$workplaceA, $workplaceB], [$namedInA]);

        $this->assertVisible($namedInA, $exam);
        $this->assertNotVisible($memberOfB, $exam);
    }

    public function testScopeSelectsTheRightExamsAcrossAMixedSet(): void
    {
        $workplace = $this->makeWorkplace('A');
        $otherWorkplace = $this->makeWorkplace('B');
        $user = $this->makeUser([$workplace]);

        $legacy = $this->makeExam();
        $allMembers = $this->makeExam([$workplace]);
        $named = $this->makeExam([$workplace], [$user]);
        $namedSomebodyElse = $this->makeExam([$workplace], [$this->makeUser([$workplace])]);
        $otherWorkplaceExam = $this->makeExam([$otherWorkplace]);

        $visibleIds = $this->visibleExamIds($user);

        $this->assertEqualsCanonicalizing([$legacy->id, $allMembers->id, $named->id], $visibleIds);
        $this->assertNotContains($namedSomebodyElse->id, $visibleIds);
        $this->assertNotContains($otherWorkplaceExam->id, $visibleIds);
    }

    private function assertVisible(User $user, Exam $exam): void
    {
        $this->assertContains($exam->id, $this->visibleExamIds($user));
    }

    private function assertNotVisible(User $user, Exam $exam): void
    {
        $this->assertNotContains($exam->id, $this->visibleExamIds($user));
    }

    private function visibleExamIds(User $user): array
    {
        $query = Exam::query();
        ($this->action)($query, $user->id, $user->workplaces()->pluck('workplaces.id'));

        return $query->pluck('exams.id')->all();
    }

    /**
     * @param Workplace[] $workplaces
     * @param User[]      $targetedUsers
     */
    private function makeExam(array $workplaces = [], array $targetedUsers = []): Exam
    {
        $exam = Exam::create([
            'is_required' => false,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
        ]);
        $exam->syncTranslations(array_fill_keys(Locale::values(), 'Exam'));
        $exam->workplaces()->sync(array_column($workplaces, 'id'));
        $exam->targetedUsers()->sync(array_column($targetedUsers, 'id'));

        return $exam;
    }

    /**
     * @param Workplace[] $workplaces
     */
    private function makeUser(array $workplaces): User
    {
        $user = User::factory()->create();
        $user->workplaces()->sync(array_column($workplaces, 'id'));
        $this->giveExamShifts($user, $user->workplaces()->pluck('workplaces.id'));

        return $user;
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
