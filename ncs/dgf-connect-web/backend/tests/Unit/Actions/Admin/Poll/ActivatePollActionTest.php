<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\ActivatePollAction;
use App\Actions\Common\Poll\FlagEligibleUsersAction;
use App\Enums\ErrorCode;
use App\Enums\PollType;
use App\Enums\UserPaperStatus;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ActivatePollActionTest extends TestCase
{
    use RefreshDatabase;

    public function testActivatesPollSuccessfully(): void
    {
        $poll = Poll::factory()->create(['is_active' => false]);

        $action = new ActivatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($poll->id);

        $this->assertNull($result->getError());
        $this->assertTrue($result->getData()->is_active);
    }

    public function testReturnsErrorWhenPollNotFound(): void
    {
        $action = new ActivatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke(99999);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testReturnsErrorWhenAlreadyActive(): void
    {
        $poll = Poll::factory()->create(['is_active' => true]);

        $action = new ActivatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($poll->id);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testFlagsEligibleUsersOnActivation(): void
    {
        Bus::fake();

        $poll = Poll::factory()->create(['is_active' => false]);

        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);

        $action = new ActivatePollAction(new FlagEligibleUsersAction());
        $action->__invoke($poll->id);

        $this->assertTrue($user->fresh()->has_pending_votes);
    }
}
