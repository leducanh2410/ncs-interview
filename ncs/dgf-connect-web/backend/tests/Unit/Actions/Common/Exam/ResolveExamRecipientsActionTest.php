<?php

namespace Tests\Unit\Actions\Common\Exam;

use App\Actions\Common\Exam\ApplyExamAudienceScopeAction;
use App\Actions\Common\Exam\ResolveExamRecipientsAction;
use App\Enums\Locale;
use App\Models\Exam;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

/**
 * The same visibility matrix as ApplyExamAudienceScopeActionTest, asserted
 * from the exam -> employees direction, plus a consistency test that pins the
 * two directions to each other over a shared fixture set.
 */
class ResolveExamRecipientsActionTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private ResolveExamRecipientsAction $action;

    private ApplyExamAudienceScopeAction $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ResolveExamRecipientsAction::class);
        $this->scope = app(ApplyExamAudienceScopeAction::class);
    }

    public function testLegacyExamReachesEveryEmployeeWithAWorkplace(): void
    {
        $workplace = $this->makeWorkplace('A');
        $member = $this->makeUser([$workplace]);
        $workplaceless = $this->makeUser([]);

        $recipients = $this->recipientIds($this->makeExam());

        $this->assertContains($member->id, $recipients);
        $this->assertNotContains($workplaceless->id, $recipients);
    }

    public function testAllMembersModeReachesEveryMemberOfEveryTargetedWorkplace(): void
    {
        $workplaceA = $this->makeWorkplace('A');
        $workplaceB = $this->makeWorkplace('B');
        $memberOfA = $this->makeUser([$workplaceA]);
        $memberOfB = $this->makeUser([$workplaceB]);
        $outsider = $this->makeUser([$this->makeWorkplace('C')]);

        $recipients = $this->recipientIds($this->makeExam([$workplaceA, $workplaceB]));

        $this->assertEqualsCanonicalizing([$memberOfA->id, $memberOfB->id], $recipients);
        $this->assertNotContains($outsider->id, $recipients);
    }

    public function testNamedMembersModeExcludesNonNamedMembersOfTheSameWorkplace(): void
    {
        $workplace = $this->makeWorkplace('A');
        $named = $this->makeUser([$workplace]);
        $notNamed = $this->makeUser([$workplace]);

        $recipients = $this->recipientIds($this->makeExam([$workplace], [$named]));

        $this->assertEquals([$named->id], $recipients);
        $this->assertNotContains($notNamed->id, $recipients);
    }

    public function testNamedEmployeeWhoLeftEveryTargetedWorkplaceIsNotARecipient(): void
    {
        $workplace = $this->makeWorkplace('A');
        $otherWorkplace = $this->makeWorkplace('B');
        $named = $this->makeUser([$workplace]);
        $exam = $this->makeExam([$workplace], [$named]);

        $named->workplaces()->sync([$otherWorkplace->id]);
        $this->giveExamShifts($named, $named->workplaces()->pluck('workplaces.id'));

        $this->assertEmpty($this->recipientIds($exam->fresh()));
    }

    /**
     * Workplace deletion cascades to exam_workplace but not to exam_user, so
     * a targeted exam can survive with named employees and no workplace. Its
     * audience is empty - it must not be mistaken for a legacy exam and
     * broadcast to everybody with a workplace.
     */
    public function testTargetedExamThatLostItsLastWorkplaceReachesNobody(): void
    {
        $deleted = $this->makeWorkplace('A');
        $survivor = $this->makeWorkplace('B');
        $named = $this->makeUser([$deleted, $survivor]);
        $this->makeUser([$survivor]);

        $exam = $this->makeExam([$deleted], [$named]);
        $this->assertEquals([$named->id], $this->recipientIds($exam));

        $this->clearExamShifts($deleted);
        $deleted->delete();

        $this->assertEmpty($this->recipientIds($exam->fresh()));
    }

    public function testWorkplacelessEmployeeIsNeverARecipient(): void
    {
        $workplace = $this->makeWorkplace('A');
        $workplaceless = $this->makeUser([]);

        $this->assertNotContains($workplaceless->id, $this->recipientIds($this->makeExam()));
        $this->assertNotContains($workplaceless->id, $this->recipientIds($this->makeExam([$workplace])));
    }

    /**
     * The two directions cannot be derived from each other, so this is what
     * stops them drifting apart: over one mixed fixture set, the recipients of
     * every exam must equal the employees the audience scope qualifies.
     */
    public function testBothDirectionsAgreeOverAMixedFixtureSet(): void
    {
        $workplaceA = $this->makeWorkplace('A');
        $workplaceB = $this->makeWorkplace('B');
        $doomedWorkplace = $this->makeWorkplace('C');

        $memberOfA = $this->makeUser([$workplaceA]);
        $memberOfB = $this->makeUser([$workplaceB]);
        $memberOfBoth = $this->makeUser([$workplaceA, $workplaceB]);
        $workplaceless = $this->makeUser([]);
        $formerMember = $this->makeUser([$workplaceA]);
        $survivorOfDeletedWorkplace = $this->makeUser([$workplaceA, $doomedWorkplace]);

        $exams = [
            $this->makeExam(),
            $this->makeExam([$workplaceA]),
            $this->makeExam([$workplaceA, $workplaceB]),
            $this->makeExam([$workplaceA], [$memberOfA]),
            $this->makeExam([$workplaceA, $workplaceB], [$memberOfA, $memberOfB]),
            $this->makeExam([$workplaceA], [$formerMember]),
            $this->makeExam([$workplaceB], [$memberOfBoth]),
            // Loses its only workplace below, keeping its named employee.
            $this->makeExam([$doomedWorkplace], [$survivorOfDeletedWorkplace]),
        ];

        // Leaves the link on the sixth exam stale.
        $formerMember->workplaces()->sync([]);
        // Cascades away the last exam's workplace, not its named employee.
        $this->clearExamShifts($doomedWorkplace);
        $doomedWorkplace->delete();

        $users = [$memberOfA, $memberOfB, $memberOfBoth, $workplaceless, $formerMember, $survivorOfDeletedWorkplace];

        foreach ($exams as $exam) {
            $fromExamSide = $this->recipientIds($exam->fresh());
            $fromEmployeeSide = [];
            foreach ($users as $user) {
                if ($this->scopeQualifies($user, $exam)) {
                    $fromEmployeeSide[] = $user->id;
                }
            }

            $this->assertEqualsCanonicalizing(
                $fromEmployeeSide,
                $fromExamSide,
                "audience mismatch between directions for exam {$exam->id}"
            );
        }
    }

    private function scopeQualifies(User $user, Exam $exam): bool
    {
        $query = Exam::query()->whereKey($exam->id);
        ($this->scope)($query, $user->id, $user->workplaces()->pluck('workplaces.id'), includeAttempted: false);

        return $query->exists();
    }

    private function recipientIds(Exam $exam): array
    {
        $query = User::query();
        ($this->action)($query, $exam);

        return $query->pluck('users.id')->all();
    }

    /**
     * @param Workplace[] $workplaces
     * @param User[]      $targetedUsers
     */
    private function makeExam(array $workplaces = [], array $targetedUsers = []): Exam
    {
        $exam = Exam::create([
            'is_required' => true,
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
