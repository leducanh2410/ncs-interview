<?php

namespace Tests\Unit\Models;

use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollTest extends TestCase
{
    use RefreshDatabase;

    public function testScopeAttributeUsesTargetTypeWhenPresent(): void
    {
        $this->assertEquals('global', Poll::factory()->global()->create()->scope);
        $this->assertEquals('workplace', Poll::factory()->workplace()->create()->scope);
        $this->assertEquals('specific_users', Poll::factory()->specificUsers()->create()->scope);
    }

    /**
     * The polls.target_type column is NOT NULL, so this fallback only applies to
     * model instances that have not been persisted yet.
     */
    public function testScopeAttributeFallsBackToWorkplaceIdWhenTargetTypeIsNull(): void
    {
        $this->assertEquals('global', (new Poll(['workplace_id' => null]))->scope);
        $this->assertEquals('workplace', (new Poll(['workplace_id' => 1]))->scope);
    }

    public function testResultCountersDefaultsToEmptyArrayWhenNull(): void
    {
        $this->assertEquals([], (new Poll())->result_counters);
    }

    public function testResultCountersAreDecodedFromJson(): void
    {
        $poll = Poll::factory()->create(['result_counters' => ['c1' => 3]]);

        $this->assertEquals(['c1' => 3], $poll->fresh()->result_counters);
    }

    public function testCastsTypeAndTargetTypeToEnums(): void
    {
        $poll = Poll::factory()->vote()->global()->create();

        $this->assertEquals(PollType::Vote, $poll->fresh()->type);
        $this->assertEquals(PollTargetType::GLOBAL, $poll->fresh()->target_type);
    }

    public function testWorkplaceRelation(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()->workplace($workplace)->create();

        $this->assertEquals($workplace->id, $poll->workplace->id);
    }

    public function testCreatorRelation(): void
    {
        $creator = User::factory()->create();
        $poll = Poll::factory()->create(['created_by' => $creator->id]);

        $this->assertEquals($creator->id, $poll->creator->id);
    }

    public function testResponsesRelation(): void
    {
        $poll = Poll::factory()->create();
        PollResponse::query()->create([
            'user_id' => User::factory()->create()->id,
            'poll_id' => $poll->id,
            'answer' => ['c1'],
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertCount(1, $poll->responses);
    }

    public function testTargetUsersRelation(): void
    {
        $poll = Poll::factory()->specificUsers()->create();
        $user = User::factory()->create();
        $poll->targetUsers()->sync([$user->id]);

        $this->assertCount(1, $poll->fresh()->targetUsers);
        $this->assertEquals($user->id, $poll->fresh()->targetUsers->first()->id);
        $this->assertEquals($poll->id, $user->fresh()->pollTargets->first()->id);
    }

    public function testScopeTypeFiltersByPollType(): void
    {
        $votePoll = Poll::factory()->vote()->create();
        Poll::factory()->feedback()->create();

        $polls = Poll::query()->type(PollType::Vote)->get();

        $this->assertCount(1, $polls);
        $this->assertEquals($votePoll->id, $polls->first()->id);
    }

    public function testScopeActiveExcludesInactiveAndExpiredPolls(): void
    {
        $activeNoExpiry = Poll::factory()->active()->create();
        $activeFutureExpiry = Poll::factory()->active()->futureExpiry()->create();
        Poll::factory()->active()->expired()->create();
        Poll::factory()->create(['is_active' => false]);

        $ids = Poll::query()->active()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$activeNoExpiry->id, $activeFutureExpiry->id], $ids);
    }

    public function testPollResponseBelongsToUserAndPoll(): void
    {
        $poll = Poll::factory()->create();
        $user = User::factory()->create();

        $response = PollResponse::query()->create([
            'user_id' => $user->id,
            'poll_id' => $poll->id,
            'answer' => ['c1'],
            'ip_address' => '127.0.0.1',
            'device_info' => ['os' => 'iOS'],
        ]);

        $this->assertEquals($user->id, $response->user->id);
        $this->assertEquals($poll->id, $response->poll->id);
        $this->assertEquals(['c1'], $response->fresh()->answer);
        $this->assertEquals(['os' => 'iOS'], $response->fresh()->device_info);
    }
}
