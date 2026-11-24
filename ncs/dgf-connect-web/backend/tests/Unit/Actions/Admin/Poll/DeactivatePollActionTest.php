<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\DeactivatePollAction;
use App\Enums\ErrorCode;
use App\Models\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivatePollActionTest extends TestCase
{
    use RefreshDatabase;

    public function testDeactivatesPollSuccessfully(): void
    {
        $poll = Poll::factory()->create(['is_active' => true]);

        $action = new DeactivatePollAction();
        $result = $action->__invoke($poll->id);

        $this->assertNull($result->getError());
        $this->assertFalse($result->getData()->is_active);
    }

    public function testReturnsErrorWhenPollNotFound(): void
    {
        $action = new DeactivatePollAction();
        $result = $action->__invoke(99999);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testReturnsErrorWhenAlreadyInactive(): void
    {
        $poll = Poll::factory()->create(['is_active' => false]);

        $action = new DeactivatePollAction();
        $result = $action->__invoke($poll->id);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testDoesNotRecalculateHasPendingVotes(): void
    {
        $poll = Poll::factory()->create(['is_active' => true]);

        $action = new DeactivatePollAction();
        $action->__invoke($poll->id);

        $this->assertFalse($poll->fresh()->is_active);
    }
}
