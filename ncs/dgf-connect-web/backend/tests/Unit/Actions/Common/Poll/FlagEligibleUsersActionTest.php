<?php

namespace Tests\Unit\Actions\Common\Poll;

use App\Actions\Common\Poll\FlagEligibleUsersAction;
use App\Enums\PollType;
use App\Jobs\SendPollAvailableFcmJob;
use App\Models\Poll;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class FlagEligibleUsersActionTest extends TestCase
{
    use RefreshDatabase;

    private FlagEligibleUsersAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new FlagEligibleUsersAction();
    }

    public function testSetsHasPendingVotesTrueForEligibleUsers(): void
    {
        Bus::fake();

        $poll = Poll::factory()->active()->global()->create();

        $user1 = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);
        $user2 = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S002',
            'has_pending_votes' => false,
        ]);

        $this->action->__invoke($poll);

        $this->assertTrue($user1->fresh()->has_pending_votes);
        $this->assertTrue($user2->fresh()->has_pending_votes);
    }

    public function testDoesNotFlagUsersWithoutStaffNo(): void
    {
        $poll = Poll::factory()->active()->global()->create();

        User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
            'has_pending_votes' => false,
        ]);

        $this->action->__invoke($poll);

        $this->assertFalse(User::first()->has_pending_votes);
    }

    public function testDoesNotFlagUsersWithoutApprovedPaperStatus(): void
    {
        $poll = Poll::factory()->active()->global()->create();

        $user = User::factory()->create([
            'paper_status' => 'pending',
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);

        $this->action->__invoke($poll);

        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testDoesNotFlagUsersWhoAlreadyResponded(): void
    {
        $poll = Poll::factory()->active()->global()->create();

        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);
        $user->pollResponses()->create([
            'poll_id' => $poll->id,
            'answer' => ['1' => 1],
            'ip_address' => '127.0.0.1',
        ]);

        $this->action->__invoke($poll);

        $this->assertFalse($user->fresh()->has_pending_votes);
    }

    public function testDispatchesFcmJobForEligibleUsers(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'title_en' => 'Test Poll',
            ]);

        $user = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S001']);

        $this->action->__invoke($poll);

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) use (
            $user,
            $poll
        ) {
            return in_array($user->id, $job->userIds) &&
                $job->data['poll_id'] === (string) $poll->id &&
                $job->data['type'] === 'poll_available';
        });
    }

    public function testOnlyFlagsWorkplaceMembersForWorkplacePoll(): void
    {
        Bus::fake();

        $workplace = Workplace::factory()->create();

        $poll = Poll::factory()->active()->workplace($workplace)->create();

        $member = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S001']);
        $member->workplaces()->attach($workplace);

        $nonMember = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S002']);

        $this->action->__invoke($poll);

        $this->assertTrue($member->fresh()->has_pending_votes);
        $this->assertFalse($nonMember->fresh()->has_pending_votes);
    }

    public function testOnlyFlagsTargetedUsersForSpecificUsersPoll(): void
    {
        Bus::fake();

        $poll = Poll::factory()->active()->specificUsers()->create();

        $targeted = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S001']);
        $notTargeted = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S002']);

        $poll->targetUsers()->sync([$targeted->id]);

        $this->action->__invoke($poll);

        $this->assertTrue($targeted->fresh()->has_pending_votes);
        $this->assertFalse($notTargeted->fresh()->has_pending_votes);
    }

    public function testDoesNotFlagDisabledUsers(): void
    {
        Bus::fake();

        $poll = Poll::factory()->active()->global()->create();

        $disabled = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'is_disabled' => true,
        ]);

        $this->action->__invoke($poll);

        $this->assertFalse($disabled->fresh()->has_pending_votes);
    }

    public function testProcessesInChunksOf500(): void
    {
        Bus::fake();

        $poll = Poll::factory()->active()->global()->create();

        User::factory()
            ->count(10)
            ->sequence(
                ['paper_status' => 'approved', 'staff_no' => 'S001', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S002', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S003', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S004', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S005', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S006', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S007', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S008', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S009', 'has_pending_votes' => false],
                ['paper_status' => 'approved', 'staff_no' => 'S010', 'has_pending_votes' => false]
            )
            ->create();

        $this->action->__invoke($poll);

        $this->assertEquals(10, User::where('has_pending_votes', true)->count());
    }

    public function testDoesNothingWhenNoEligibleUsers(): void
    {
        $poll = Poll::factory()->active()->global()->create();

        User::factory()->create(['paper_status' => 'approved', 'staff_no' => null]);

        $this->action->__invoke($poll);

        $this->assertFalse(User::first()->has_pending_votes);
    }

    public function testBuildsNepaliAndSinhalaContentWithEnglishTitleFallback(): void
    {
        Bus::fake();

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'title_en' => 'Test Poll',
                'title_ne' => null,
                'title_si' => null,
            ]);

        User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S001']);

        $this->action->__invoke($poll);

        Bus::assertDispatched(SendPollAvailableFcmJob::class, function (SendPollAvailableFcmJob $job) {
            return str_contains($job->contentByLocale['ne'], 'Test Poll') &&
                str_contains($job->contentByLocale['si'], 'Test Poll');
        });
    }
}
