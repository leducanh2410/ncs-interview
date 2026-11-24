<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\DeletePollAction;
use App\Enums\ErrorCode;
use App\Models\Poll;
use App\Models\PollResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletePollActionTest extends TestCase
{
    use RefreshDatabase;

    public function testDeletesPollSuccessfully(): void
    {
        $poll = Poll::factory()->create();

        $action = new DeletePollAction();
        $result = $action->__invoke($poll->id);

        $this->assertNull($result->getError());
        $this->assertTrue($result->getData());
        $this->assertDatabaseMissing('polls', ['id' => $poll->id]);
    }

    public function testReturnsErrorWhenPollNotFound(): void
    {
        $action = new DeletePollAction();
        $result = $action->__invoke(99999);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testPreventsDeletionWhenResponsesExist(): void
    {
        $poll = Poll::factory()->create();

        PollResponse::factory()->forPoll($poll)->create();

        $action = new DeletePollAction();
        $result = $action->__invoke($poll->id);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertDatabaseHas('polls', ['id' => $poll->id]);
    }

    public function testDeletesPollAndResponsesWhenNoResponses(): void
    {
        $poll = Poll::factory()->create();

        $action = new DeletePollAction();
        $action->__invoke($poll->id);

        $this->assertDatabaseMissing('polls', ['id' => $poll->id]);
    }
}
