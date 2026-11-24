<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\ActionResult;
use App\Actions\Admin\Poll\GetPollResponsesAction;
use App\Enums\ErrorCode;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetPollResponsesActionTest extends TestCase
{
    use RefreshDatabase;

    private GetPollResponsesAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new GetPollResponsesAction();
    }

    public function testReturnsNotFoundForMissingPoll(): void
    {
        $result = $this->action->__invoke(999999);

        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testReturnsPaginatedResponsesWithUserAndPollAttached(): void
    {
        $poll = Poll::factory()->create();
        $user = User::factory()->create();

        PollResponse::query()->create([
            'user_id' => $user->id,
            'poll_id' => $poll->id,
            'answer' => ['c1'],
            'ip_address' => '127.0.0.1',
        ]);

        $result = $this->action->__invoke($poll->id);

        $this->assertEquals(1, $result->total());

        $response = $result->items()[0];
        $this->assertTrue($response->relationLoaded('user'));
        // The action replaces the `user` relation with its display array so the
        // Sri Lanka short_name is applied to `name`, so `user` is an array here.
        $this->assertEquals($user->id, $response->user['id']);
        $this->assertEquals($poll->id, $response->poll->id);
    }

    public function testOrdersResponsesByCreatedAtDescending(): void
    {
        $poll = Poll::factory()->create();

        $older = PollResponse::query()->create([
            'user_id' => User::factory()->create()->id,
            'poll_id' => $poll->id,
            'answer' => ['c1'],
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDay(),
        ]);
        $newer = PollResponse::query()->create([
            'user_id' => User::factory()->create()->id,
            'poll_id' => $poll->id,
            'answer' => ['c2'],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $result = $this->action->__invoke($poll->id);

        $this->assertEquals($newer->id, $result->items()[0]->id);
        $this->assertEquals($older->id, $result->items()[1]->id);
    }

    public function testExcludesResponsesFromOtherPolls(): void
    {
        $poll = Poll::factory()->create();
        $otherPoll = Poll::factory()->create();

        PollResponse::query()->create([
            'user_id' => User::factory()->create()->id,
            'poll_id' => $otherPoll->id,
            'answer' => ['c1'],
            'ip_address' => '127.0.0.1',
        ]);

        $result = $this->action->__invoke($poll->id);

        $this->assertEquals(0, $result->total());
    }

    public function testRespectsPerPage(): void
    {
        $poll = Poll::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            PollResponse::query()->create([
                'user_id' => User::factory()->create()->id,
                'poll_id' => $poll->id,
                'answer' => ['c1'],
                'ip_address' => '127.0.0.1',
            ]);
        }

        $result = $this->action->__invoke($poll->id, 2);

        $this->assertEquals(3, $result->total());
        $this->assertCount(2, $result->items());
    }
}
