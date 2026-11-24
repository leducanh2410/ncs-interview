<?php

namespace Tests\Unit\Actions\Common\Recurring;

use App\Actions\Common\Recurring\LoadCountableShiftsAction;
use App\Actions\Common\Recurring\RecordRecurringCompletionAction;
use App\Actions\Common\Recurring\ResolveRecurringTriggersAction;
use App\Enums\PollTargetType;
use App\Enums\RecurringCompletableType;
use App\Enums\RecurringCompletionSourceType;
use App\Enums\ShiftUserStatus;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\ValueObjects\Recurring\CountableShift;
use App\ValueObjects\Recurring\RecurringAnchor;
use App\ValueObjects\Recurring\RecurringTarget;
use App\ValueObjects\Recurring\RecurringTrigger;
use App\ValueObjects\Recurring\ShiftPosition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsRecurringShiftFixtures;
use Tests\TestCase;

class ResolveRecurringTriggersActionTest extends TestCase
{
    use BuildsRecurringShiftFixtures;
    use RefreshDatabase;

    private const WORKPLACE_ID = 1;

    private ResolveRecurringTriggersAction $action;

    private int $nextSortId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 10:00:00');
        $this->action = app(ResolveRecurringTriggersAction::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testFirstCountableShiftTriggersWithoutAnchor(): void
    {
        $upcoming = $this->shift('2026-10-05', worked: false);

        $trigger = $this->walk([$upcoming], null);

        $this->assertTriggerAt($upcoming, $trigger);
        $this->assertSame(1, $trigger->occurrence);
        $this->assertSame($upcoming->position, $trigger->completionAnchor);
    }

    public function testEleventhShiftTriggersAfterNineWorkedShifts(): void
    {
        $anchor = $this->shift('2026-09-01', worked: true);
        $worked = $this->workedDays('2026-09-02', 9);
        $eleventh = $this->shift('2026-09-29', worked: false);

        $trigger = $this->walk([$anchor, ...$worked, $eleventh], $this->anchorAt($anchor, 1));

        $this->assertTriggerAt($eleventh, $trigger);
        $this->assertSame(2, $trigger->occurrence);
    }

    public function testTenthShiftDoesNotTrigger(): void
    {
        $anchor = $this->shift('2026-09-01', worked: true);
        $worked = $this->workedDays('2026-09-02', 8);
        $tenth = $this->shift('2026-09-29', worked: false);

        $this->assertNull($this->walk([$anchor, ...$worked, $tenth], $this->anchorAt($anchor, 1)));
    }

    public function testUpcomingShiftAfterUnworkedUpcomingOnlyTriggersOnceItIsWorked(): void
    {
        $anchor = $this->shift('2026-09-10', worked: true);
        $worked = $this->workedDays('2026-09-11', 8);
        $shiftA = $this->shift('2026-09-29', worked: false);
        $shiftB = $this->shift('2026-09-30', worked: false);

        $this->assertNull($this->walk([$anchor, ...$worked, $shiftA, $shiftB], $this->anchorAt($anchor, 1)));

        $workedA = new CountableShift(self::WORKPLACE_ID, $shiftA->position, true);
        $trigger = $this->walk([$anchor, ...$worked, $workedA, $shiftB], $this->anchorAt($anchor, 1));

        $this->assertTriggerAt($shiftB, $trigger);
    }

    public function testNextUpcomingShiftTakesOverWhenTheUpcomingTriggerIsRemoved(): void
    {
        $anchor = $this->shift('2026-09-01', worked: true);
        $worked = $this->workedDays('2026-09-02', 9);
        $shiftA = $this->shift('2026-09-29', worked: false);
        $shiftB = $this->shift('2026-09-30', worked: false);

        $this->assertTriggerAt(
            $shiftA,
            $this->walk([$anchor, ...$worked, $shiftA, $shiftB], $this->anchorAt($anchor, 1))
        );
        $this->assertTriggerAt($shiftB, $this->walk([$anchor, ...$worked, $shiftB], $this->anchorAt($anchor, 1)));
    }

    public function testShiftsApprovedAMonthInAdvanceDoNotLookLikeAGap(): void
    {
        $anchor = $this->shift('2026-09-20', worked: true);
        $worked = $this->workedDays('2026-09-21', 7);
        $upcoming = [];
        for ($day = 1; $day <= 31; $day++) {
            $upcoming[] = $this->shift(sprintf('2026-10-%02d', $day), worked: false);
        }

        $this->assertNull($this->walk([$anchor, ...$worked, ...$upcoming], $this->anchorAt($anchor, 1)));
    }

    public function testGapOfMoreThanThirtyDaysTriggers(): void
    {
        $anchor = $this->shift('2026-08-10', worked: true);
        $afterGap = $this->shift('2026-09-10', worked: true);

        $this->assertTriggerAt($afterGap, $this->walk([$anchor, $afterGap], $this->anchorAt($anchor, 1)));
    }

    public function testGapOfExactlyThirtyDaysDoesNotTrigger(): void
    {
        $anchor = $this->shift('2026-08-10', worked: true);
        $afterGap = $this->shift('2026-09-09', worked: true);

        $this->assertNull($this->walk([$anchor, $afterGap], $this->anchorAt($anchor, 1)));
    }

    public function testGapTriggerRestartsTheTenShiftCount(): void
    {
        $anchor = $this->shift('2026-07-01', worked: true);
        $afterGap = $this->shift('2026-09-05', worked: true);
        $worked = $this->workedDays('2026-09-06', 9);
        $next = $this->shift('2026-09-29', worked: false);

        $trigger = $this->walk([$anchor, $afterGap, ...$worked, $next], $this->anchorAt($anchor, 1));
        $this->assertTriggerAt($next, $trigger);

        $trigger = $this->walk([$anchor, $afterGap, ...array_slice($worked, 0, 8), $next], $this->anchorAt($anchor, 1));
        $this->assertTriggerAt($afterGap, $trigger);
    }

    public function testLateCompletionAnchorsAtTheLatestWorkedShift(): void
    {
        $shifts = $this->workedDays('2026-09-01', 15);

        $trigger = $this->walk($shifts, null);

        $this->assertTriggerAt($shifts[10], $trigger);
        $this->assertSame(1, $trigger->occurrence);
        $this->assertSame($shifts[14]->position, $trigger->completionAnchor);
    }

    public function testMissedOccurrencesReturnOnlyTheLatestTrigger(): void
    {
        $shifts = $this->workedDays('2026-09-01', 21);

        $trigger = $this->walk($shifts, $this->anchorAt($shifts[0], 1));

        $this->assertTriggerAt($shifts[20], $trigger);
        $this->assertSame(2, $trigger->occurrence);
    }

    public function testLongHistoryWithoutAnchorTriggersNowAndAnchorsAtTheLatestWorkedShift(): void
    {
        $history = [];
        $date = CarbonImmutable::parse('2026-07-01');
        for ($i = 0; $i < 57; $i++) {
            $history[] = $this->shift($date->toDateString(), worked: true);
            $date = $date->addDay();
        }

        $trigger = $this->walk($history, null);

        $this->assertTriggerAt($history[50], $trigger);
        $this->assertSame($history[56]->position, $trigger->completionAnchor);

        $nextWorked = $this->workedDays($date->toDateString(), 9);
        $upcoming = $this->shift('2026-10-01', worked: false);
        $next = $this->walk(
            [...$history, ...$nextWorked, $upcoming],
            new RecurringAnchor($trigger->completionAnchor, 1)
        );

        $this->assertTriggerAt($upcoming, $next);
    }

    public function testUpcomingAnchorThatWasNeverWorkedStillServesAsGapReference(): void
    {
        $removedAnchor = $this->shift('2026-10-05', worked: false);
        $next = $this->shift('2026-11-20', worked: false);

        $trigger = $this->walk([$next], $this->anchorAt($removedAnchor, 1));

        $this->assertTriggerAt($next, $trigger);
    }

    public function testShiftsBeforeTheAnchorAreIgnored(): void
    {
        $before = $this->workedDays('2026-09-01', 12);
        $anchor = $this->shift('2026-09-13', worked: true);

        $this->assertNull($this->walk([...$before, $anchor], $this->anchorAt($anchor, 1)));
    }

    public function testLoaderKeepsWorkedAndUpcomingShiftsOnly(): void
    {
        [$user, $workplace] = $this->employee();
        $worked = $this->recurringShift($user, $workplace, '2026-09-01', worked: true);
        $this->recurringShift($user, $workplace, '2026-09-02');
        $this->recurringShift($user, $workplace, '2026-09-03', worked: true, isAbsent: true);
        $this->recurringShift($user, $workplace, '2026-09-04', status: ShiftUserStatus::Rejected);
        $this->recurringShift($user, $workplace, '2026-10-02', status: ShiftUserStatus::Pending);
        $today = $this->recurringShift($user, $workplace, '2026-09-28');
        $upcoming = $this->recurringShift($user, $workplace, '2026-10-01');

        $shifts = app(LoadCountableShiftsAction::class)($user->id, [$workplace->id])->get($workplace->id);

        $this->assertSame(
            [$worked->id, $today->id, $upcoming->id],
            array_map(fn(CountableShift $shift) => $shift->position->shiftUserId, $shifts)
        );
        $this->assertSame([true, false, false], array_map(fn(CountableShift $shift) => $shift->worked, $shifts));
    }

    public function testPastShiftWithoutCheckOutIsNotAGapReference(): void
    {
        [$user, $workplace] = $this->employee();
        $anchor = $this->recurringShift($user, $workplace, '2026-08-10', worked: true);
        $this->recordCompletion($user, $workplace, $anchor, 1);
        $this->recurringShift($user, $workplace, '2026-09-05');
        $upcoming = $this->recurringShift($user, $workplace, '2026-09-11', worked: true);

        $trigger = $this->resolve($user, $workplace, 10);

        $this->assertSame($upcoming->id, $trigger?->trigger->shiftUserId);
        $this->assertSame(2, $trigger->occurrence);
    }

    public function testDeletedAnchorShiftStillPositionsTheWalk(): void
    {
        [$user, $workplace] = $this->employee();
        $anchor = $this->recurringShift($user, $workplace, '2026-09-01', worked: true);
        $this->recordCompletion($user, $workplace, $anchor, 1);
        $this->recurringWorkedDays($user, $workplace, '2026-09-02', 9);
        $anchor->shift->delete();

        $this->assertNull($this->resolve($user, $workplace, 10));

        $upcoming = $this->recurringShift($user, $workplace, '2026-09-29');
        $trigger = $this->resolve($user, $workplace, 10);

        $this->assertSame($upcoming->id, $trigger?->trigger->shiftUserId);
        $this->assertSame(2, $trigger->occurrence);
    }

    public function testLegacyCompletionAnchorsAtTheLatestWorkedShiftBeforeIt(): void
    {
        [$user, $workplace] = $this->employee();
        $poll = $this->recurringPoll($workplace);
        $this->recurringWorkedDays($user, $workplace, '2026-09-01', 4);
        $this->legacyResponse($user, $poll, '2026-09-04 20:00:00');
        $this->recurringShift($user, $workplace, '2026-09-06', worked: true);

        $this->assertNull($this->resolveItem($user, $workplace, RecurringCompletableType::Poll, $poll->id));
    }

    public function testLegacyCompletionWithoutWorkedShiftBeforeItTriggersAsTheSecondOccurrence(): void
    {
        [$user, $workplace] = $this->employee();
        $poll = $this->recurringPoll($workplace);
        $this->legacyResponse($user, $poll, '2026-09-04 20:00:00');
        $first = $this->recurringShift($user, $workplace, '2026-09-06', worked: true);

        $trigger = $this->resolveItem($user, $workplace, RecurringCompletableType::Poll, $poll->id);

        $this->assertSame($first->id, $trigger?->trigger->shiftUserId);
        $this->assertSame(2, $trigger->occurrence);
    }

    public function testWorkplacesAreEvaluatedIndependently(): void
    {
        [$user, $workplaceA] = $this->employee();
        $workplaceB = Workplace::factory()->create();
        $shiftA = $this->recurringShift($user, $workplaceA, '2026-09-20', worked: true);
        $shiftB = $this->recurringShift($user, $workplaceB, '2026-09-21', worked: true);
        $this->recordCompletion($user, $workplaceA, $shiftA, 1);

        $target = new RecurringTarget(RecurringCompletableType::Exam, 10, [$workplaceA->id, $workplaceB->id]);
        $triggers = ($this->action)($user->id, [$target]);

        $this->assertNull($triggers[$target->key($workplaceA->id)]);
        $this->assertSame($shiftB->id, $triggers[$target->key($workplaceB->id)]?->trigger->shiftUserId);
        $this->assertSame(1, $triggers[$target->key($workplaceB->id)]->occurrence);
    }

    private function shift(string $date, bool $worked): CountableShift
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $sortId = $this->nextSortId++;

        return new CountableShift(
            self::WORKPLACE_ID,
            new ShiftPosition($sortId, $day, $day->setTime(9, 0), $sortId),
            $worked
        );
    }

    /**
     * @return list<CountableShift>
     */
    private function workedDays(string $firstDate, int $count): array
    {
        $shifts = [];
        $date = CarbonImmutable::parse($firstDate);
        for ($i = 0; $i < $count; $i++) {
            $shifts[] = $this->shift($date->toDateString(), worked: true);
            $date = $date->addDay();
        }

        return $shifts;
    }

    private function anchorAt(CountableShift $shift, int $lastOccurrence): RecurringAnchor
    {
        return new RecurringAnchor($shift->position, $lastOccurrence);
    }

    /**
     * @param list<CountableShift> $shifts
     */
    private function walk(array $shifts, ?RecurringAnchor $anchor): ?RecurringTrigger
    {
        return $this->action->resolveForWorkplace(self::WORKPLACE_ID, $shifts, $anchor);
    }

    private function assertTriggerAt(CountableShift $expected, ?RecurringTrigger $trigger): void
    {
        $this->assertNotNull($trigger);
        $this->assertSame($expected->position->shiftUserId, $trigger->trigger->shiftUserId);
    }

    /**
     * @return array{0: User, 1: Workplace}
     */
    private function employee(): array
    {
        return [User::factory()->create(), Workplace::factory()->create()];
    }

    private function recordCompletion(User $user, Workplace $workplace, ShiftUser $anchor, int $occurrence): void
    {
        $date = CarbonImmutable::parse($anchor->shift->date)->startOfDay();
        $position = new ShiftPosition($anchor->id, $date, $date->setTime(9, 0), $anchor->id);

        app(
            RecordRecurringCompletionAction::class
        )($user->id, RecurringCompletableType::Exam, 10, new RecurringTrigger($workplace->id, $position, $position, $occurrence), RecurringCompletionSourceType::ExamResult, 1);
    }

    private function resolve(User $user, Workplace $workplace, int $examId): ?RecurringTrigger
    {
        return $this->resolveItem($user, $workplace, RecurringCompletableType::Exam, $examId);
    }

    private function resolveItem(
        User $user,
        Workplace $workplace,
        RecurringCompletableType $type,
        int $itemId
    ): ?RecurringTrigger {
        $target = new RecurringTarget($type, $itemId, [$workplace->id]);

        return ($this->action)($user->id, [$target])[$target->key($workplace->id)] ?? null;
    }

    private function recurringPoll(Workplace $workplace): Poll
    {
        return Poll::factory()
            ->feedback()
            ->active()
            ->create([
                'target_type' => PollTargetType::WORKPLACE->value,
                'workplace_id' => $workplace->id,
                'is_required' => true,
                'is_recurring' => true,
            ]);
    }

    private function legacyResponse(User $user, Poll $poll, string $createdAt): void
    {
        $response = PollResponse::query()->create([
            'user_id' => $user->id,
            'poll_id' => $poll->id,
            'answer' => ['a'],
            'ip_address' => '127.0.0.1',
        ]);
        $response->forceFill(['created_at' => $createdAt])->save();
    }
}
