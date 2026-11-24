<?php

namespace Tests\Feature\Api\User;

use App\Actions\Common\Recurring\RecordRecurringCompletionAction;
use App\Enums\PermissionName;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Enums\RecurringCompletableType;
use App\Models\Permission;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\RecurringCompletion;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\DrivesRecurringShiftFlow;
use Tests\TestCase;

class RecurringFeedbackFlowTest extends TestCase
{
    use DrivesRecurringShiftFlow;
    use RefreshDatabase;

    private const CHOICE = '01RECURRINGCHOICE';

    private Workplace $workplace;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelToDate('2026-09-01 08:00:00');
        $this->setUpRecurringShiftFlow();

        foreach ([PermissionName::PollCreate, PermissionName::PollEdit, PermissionName::PollList] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name->value], ['display_name' => $name->value]);
            $this->shiftManager->givePermission($permission, null);
        }

        $this->workplace = Workplace::factory()->create();
        $this->employee = $this->createRecurringMember($this->workplace);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testLifecycleFromFirstShiftToEleventhShift(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->assertTrue($poll->is_required);
        $this->assertSame([], $this->pendingPollIds($this->employee));

        $first = $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));
        $this->assertTrue((bool) $this->authCheck($this->employee)->json('data.pending_votes.0.is_required'));

        $this->respond($poll)->assertOk()->assertJsonPath('data.has_pending_votes', false);
        $this->assertSame([], $this->pendingPollIds($this->employee));
        $this->assertResponseRound($first->id, 1);

        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-02', 9);
        $this->assertSame([], $this->pendingPollIds($this->employee));

        $this->travelToDate('2026-09-11 08:00:00');
        $eleventh = $this->approveShift($this->employee, $this->workplace, '2026-09-11');
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));

        $this->respond($poll)->assertOk();
        $this->assertResponseRound($eleventh->id, 2);
        $this->assertSame([], $this->pendingPollIds($this->employee));
        $this->assertSame(2, PollResponse::query()->where('poll_id', $poll->id)->count());
        $this->assertSame(2, $poll->fresh()->result_counters[self::CHOICE]);
    }

    public function testApprovedFutureShiftMakesThePollPendingAndRejectingItRemovesIt(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();

        $future = $this->approveShift($this->employee, $this->workplace, '2026-09-20');
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));

        $this->rejectShift($future);
        $this->assertSame([], $this->pendingPollIds($this->employee));
    }

    public function testShiftsApprovedAMonthInAdvanceDoNotMakeThePollPending(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 1);
        $this->respond($poll)->assertOk();
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-02', 3);

        $this->travelToDate('2026-09-04 20:00:00');
        for ($day = 1; $day <= 31; $day++) {
            $this->approveShift($this->employee, $this->workplace, sprintf('2026-10-%02d', $day));
        }

        $this->assertSame([], $this->pendingPollIds($this->employee));
    }

    public function testGapOfMoreThanThirtyDaysMakesThePollPending(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 1);
        $this->respond($poll)->assertOk();

        $this->travelToDate('2026-09-20 08:00:00');
        $bridge = $this->approveShift($this->employee, $this->workplace, '2026-10-01');
        $this->assertSame([], $this->pendingPollIds($this->employee));

        $afterGap = $this->approveShift($this->employee, $this->workplace, '2026-10-25');
        $this->assertSame([], $this->pendingPollIds($this->employee));

        $this->rejectShift($bridge);
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));

        $this->respond($poll)->assertOk();
        $this->assertResponseRound($afterGap->id, 2);
    }

    public function testLateResponseCountsTheNextCycleFromTheLatestWorkedShift(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $shifts = $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 5);

        $this->respond($poll)->assertOk();
        $completion = RecurringCompletion::query()->firstOrFail();
        $this->assertSame($shifts[0]->id, $completion->trigger_shift_user_id);
        $this->assertSame($shifts[4]->id, $completion->anchor_shift_user_id);

        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-06', 9);
        $this->assertSame([], $this->pendingPollIds($this->employee));

        $this->travelToDate('2026-09-15 08:00:00');
        $this->approveShift($this->employee, $this->workplace, '2026-09-15');
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));
    }

    public function testPollEnabledForEmployeeWithLongHistoryIsPendingOnceAndCountsFromNow(): void
    {
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 14);
        $poll = $this->createRecurringPollThroughAdmin();

        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));
        $this->respond($poll)->assertOk();
        $this->assertSame(1, PollResponse::query()->where('poll_id', $poll->id)->value('occurrence'));

        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-15', 3);
        $this->assertSame([], $this->pendingPollIds($this->employee));
    }

    public function testOutOfOrderWorkHistoryShiftCountsOnlyAfterTheAnchor(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-10', 1);
        $this->respond($poll)->assertOk();
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-11', 8);

        $this->travelToDate('2026-09-19 20:00:00');
        $this->addWorkedShiftThroughWorkHistory($this->employee, $this->workplace, '2026-09-05');
        $this->approveShift($this->employee, $this->workplace, '2026-09-25');
        $this->assertSame([], $this->pendingPollIds($this->employee));

        $this->addWorkedShiftThroughWorkHistory($this->employee, $this->workplace, '2026-09-19');
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));
    }

    public function testRespondingWithoutTriggerOrTwiceInTheSameRoundIsRejected(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->respond($poll)->assertStatus(422)->assertJsonPath('data.message', __('poll.already_responded'));

        $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        $this->respond($poll)->assertOk();
        $this->respond($poll)->assertStatus(422)->assertJsonPath('data.message', __('poll.already_responded'));
        $this->assertSame(1, PollResponse::query()->where('poll_id', $poll->id)->count());
    }

    public function testDuePollIsReturnedEvenWhenThePendingFlagIsOff(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->approveShift($this->employee, $this->workplace, '2026-09-01');
        User::query()
            ->whereKey($this->employee->id)
            ->update(['has_pending_votes' => false]);

        $response = $this->authCheck($this->employee->fresh());

        $this->assertSame([$poll->id], collect($response->json('data.pending_votes'))->pluck('id')->all());
        $this->assertTrue($this->employee->fresh()->has_pending_votes);
    }

    public function testLegacyResponseCountsAsTheFirstOccurrence(): void
    {
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 2);
        $poll = $this->createPoll(isRecurring: false);
        $this->respond($poll)->assertOk();

        $this->actingAs($this->shiftManager, 'api')
            ->putJson("/manage/polls/{$poll->id}", $this->pollTitles() + ['is_recurring' => true])
            ->assertOk();

        $this->assertSame([], $this->pendingPollIds($this->employee));

        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-03', 9);
        $this->travelToDate('2026-09-12 08:00:00');
        $this->approveShift($this->employee, $this->workplace, '2026-09-12');
        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee));

        $this->respond($poll)->assertOk();
        $this->assertSame(
            [1, 2],
            PollResponse::query()->where('poll_id', $poll->id)->orderBy('occurrence')->pluck('occurrence')->all()
        );
    }

    public function testTurningRecurringOffMakesTheUnansweredPollPendingAgain(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $responded = $this->createRecurringMember($this->workplace);
        $this->approveShift($responded, $this->workplace, '2026-09-01');
        $this->actingAs($responded, 'api')
            ->postJson("/user/polls/{$poll->id}/respond", ['answer' => [self::CHOICE]])
            ->assertOk();

        $this->assertSame([], $this->pendingPollIds($this->employee));
        $this->assertFalse($this->employee->fresh()->has_pending_votes);

        $this->actingAs($this->shiftManager, 'api')
            ->putJson("/manage/polls/{$poll->id}", $this->pollTitles() + ['is_recurring' => false])
            ->assertOk();

        $this->assertSame([$poll->id], $this->pendingPollIds($this->employee->fresh()));
        $this->assertSame([], $this->pendingPollIds($responded->fresh()));
    }

    public function testVoteAndOneTimeFeedbackPollsKeepOneResponseWithAccumulatedHistory(): void
    {
        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-01', 12);
        $oneTime = $this->createPoll(isRecurring: false);
        $candidate = User::factory()->create();
        $vote = Poll::factory()
            ->vote()
            ->active()
            ->create([
                'target_type' => PollTargetType::WORKPLACE->value,
                'workplace_id' => $this->workplace->id,
                'options' => [$candidate->id],
                'result_counters' => [(string) $candidate->id => ['yes' => 0, 'no' => 0, 'blank' => 0]],
            ]);
        User::query()
            ->whereKey($this->employee->id)
            ->update(['has_pending_votes' => true]);

        $this->respond($oneTime)->assertOk();
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/polls/{$vote->id}/respond", ['answer' => [(string) $candidate->id => 1]])
            ->assertOk();

        $this->approveAndWorkDays($this->employee, $this->workplace, '2026-09-13', 12);

        $this->assertSame([], $this->pendingPollIds($this->employee->fresh()));
        $this->respond($oneTime)->assertStatus(422);
        $this->actingAs($this->employee, 'api')
            ->postJson("/user/polls/{$vote->id}/respond", ['answer' => [(string) $candidate->id => 1]])
            ->assertStatus(422);
        $this->assertSame(1, PollResponse::query()->where('poll_id', $oneTime->id)->count());
        $this->assertSame(1, PollResponse::query()->where('poll_id', $vote->id)->count());
    }

    public function testFailedCompletionRecordRollsBackTheResponse(): void
    {
        $poll = $this->createRecurringPollThroughAdmin();
        $this->approveShift($this->employee, $this->workplace, '2026-09-01');

        $this->mock(RecordRecurringCompletionAction::class)
            ->shouldReceive('__invoke')
            ->andThrow(new RuntimeException('completion store failed'));

        $this->withoutExceptionHandling();
        try {
            $this->respond($poll);
            $this->fail('The response should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('completion store failed', $exception->getMessage());
        }

        $this->assertSame(0, PollResponse::query()->where('poll_id', $poll->id)->count());
        $this->assertSame(0, RecurringCompletion::query()->count());
        $this->assertSame(0, $poll->fresh()->result_counters[self::CHOICE]);
    }

    private function createRecurringPollThroughAdmin(): Poll
    {
        return $this->createPoll(isRecurring: true);
    }

    private function createPoll(bool $isRecurring): Poll
    {
        $response = $this->actingAs($this->shiftManager, 'api')
            ->postJson(
                '/manage/polls',
                $this->pollTitles() + [
                    'type' => PollType::Feedback->value,
                    'target_type' => PollTargetType::WORKPLACE->value,
                    'workplace_id' => $this->workplace->id,
                    'is_active' => true,
                    'is_required' => false,
                    'is_recurring' => $isRecurring,
                    'is_multiple' => false,
                    'options' => [['id' => self::CHOICE, 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
                ]
            )
            ->assertOk();

        return Poll::query()->findOrFail($response->json('data.id'));
    }

    private function pollTitles(): array
    {
        return ['title_vi' => 'Phản hồi', 'title_en' => 'Feedback', 'title_ja' => 'フィードバック'];
    }

    private function respond(Poll $poll): TestResponse
    {
        return $this->actingAs($this->employee, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => [self::CHOICE],
        ]);
    }

    private function assertResponseRound(int $triggerShiftUserId, int $occurrence): void
    {
        $response = PollResponse::query()->where('occurrence', $occurrence)->firstOrFail();
        $this->assertSame($this->workplace->id, $response->workplace_id);

        $completion = RecurringCompletion::query()
            ->where('completable_type', RecurringCompletableType::Poll->value)
            ->where('occurrence', $occurrence)
            ->firstOrFail();
        $this->assertSame($triggerShiftUserId, $completion->trigger_shift_user_id);
        $this->assertSame($response->id, $completion->source_id);
    }
}
