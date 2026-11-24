<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\GetPollsAction;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetPollsActionTest extends TestCase
{
    use RefreshDatabase;

    private GetPollsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new GetPollsAction();
    }

    public function testReturnsAllPollsOrderedByIdDescending(): void
    {
        $first = Poll::factory()->create();
        $second = Poll::factory()->create();

        $result = $this->action->__invoke();

        $this->assertEquals(2, $result->total());
        $this->assertEquals($second->id, $result->items()[0]->id);
        $this->assertEquals($first->id, $result->items()[1]->id);
    }

    public function testFiltersByType(): void
    {
        Poll::factory()->vote()->create();
        Poll::factory()->feedback()->create();

        $result = $this->action->__invoke(['type' => 'vote']);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('vote', $result->items()[0]->type->value);
    }

    public function testFiltersByWorkplaceId(): void
    {
        $workplace = Workplace::factory()->create();
        $target = Poll::factory()->workplace($workplace)->create();
        Poll::factory()->global()->create();

        $result = $this->action->__invoke(['workplace_id' => $workplace->id]);

        $this->assertEquals(1, $result->total());
        $this->assertEquals($target->id, $result->items()[0]->id);
    }

    public function testFiltersByIsActive(): void
    {
        $active = Poll::factory()->active()->create();
        Poll::factory()->create(['is_active' => false]);

        $result = $this->action->__invoke(['is_active' => true]);

        $this->assertEquals(1, $result->total());
        $this->assertEquals($active->id, $result->items()[0]->id);
    }

    public function testRespectsPerPage(): void
    {
        Poll::factory()->count(3)->create();

        $result = $this->action->__invoke([], 2);

        $this->assertEquals(3, $result->total());
        $this->assertCount(2, $result->items());
        $this->assertEquals(2, $result->perPage());
    }

    public function testEagerLoadsWorkplaceAndResponsesCount(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()->workplace($workplace)->create();

        PollResponse::query()->create([
            'user_id' => User::factory()->create()->id,
            'poll_id' => $poll->id,
            'answer' => ['c1'],
            'ip_address' => '127.0.0.1',
        ]);

        $result = $this->action->__invoke();

        $this->assertTrue($result->items()[0]->relationLoaded('workplace'));
        $this->assertEquals(1, $result->items()[0]->responses_count);
    }
}
