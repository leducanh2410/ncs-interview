<?php

namespace Tests\Unit\Actions\Common\Poll;

use App\Actions\Common\Poll\GetPendingPollsAction;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetPendingPollsActionTest extends TestCase
{
    use RefreshDatabase;

    private GetPendingPollsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new GetPendingPollsAction();
    }

    public function testReturnsEmptyWhenUserHasNoStaffNo(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => null,
        ]);

        $poll = Poll::factory()->active()->global()->create();

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }

    public function testReturnsActiveGlobalPollsForEligibleUser(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'title_en' => 'Test Poll',
            ]);

        $result = $this->action->__invoke($user);

        $this->assertCount(1, $result);
        $this->assertEquals($poll->id, $result->first()->id);
    }

    public function testExcludesInactivePolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);

        Poll::factory()->create(['is_active' => false]);

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }

    public function testExcludesExpiredPolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);

        Poll::factory()->active()->expired()->create();

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }

    public function testExcludesAlreadyRespondedPolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);

        $poll = Poll::factory()->active()->global()->create();

        PollResponse::factory()->forPoll($poll)->forUser($user)->create();

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }

    public function testReturnsWorkplacePollsForMember(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);
        $workplace = Workplace::factory()->create();

        $user->workplaces()->attach($workplace);

        $poll = Poll::factory()->active()->workplace($workplace)->create();

        $result = $this->action->__invoke($user);

        $this->assertCount(1, $result);
        $this->assertEquals($poll->id, $result->first()->id);
    }

    public function testExcludesWorkplacePollsForNonMember(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);
        $workplace = Workplace::factory()->create();

        Poll::factory()->active()->workplace($workplace)->create();

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }

    public function testReturnsBothGlobalAndWorkplacePolls(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);
        $workplace = Workplace::factory()->create();

        $user->workplaces()->attach($workplace);

        $globalPoll = Poll::factory()->active()->global()->create();
        $workplacePoll = Poll::factory()->active()->workplace($workplace)->create();

        $result = $this->action->__invoke($user);

        $this->assertCount(2, $result);
    }

    public function testExcludesPollsUserNotEligibleFor(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);
        $workplace = Workplace::factory()->create();

        $otherWorkplace = Workplace::factory()->create();

        $user->workplaces()->attach($workplace);

        Poll::factory()->active()->workplace($otherWorkplace)->create();

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }

    public function testReturnsPollsOrderedById(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
        ]);

        $poll1 = Poll::factory()
            ->active()
            ->global()
            ->create(['title_en' => 'First']);
        $poll2 = Poll::factory()
            ->active()
            ->global()
            ->create(['title_en' => 'Second']);

        $result = $this->action->__invoke($user);

        $this->assertEquals($poll1->id, $result->first()->id);
    }

    public function testReturnsEmptyWhenUserIsNotApproved(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'pending',
            'staff_no' => 'S001',
        ]);

        Poll::factory()->active()->global()->create();

        $result = $this->action->__invoke($user);

        $this->assertTrue($result->isEmpty());
    }
}
