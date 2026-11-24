<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\GetPollAction;
use App\Enums\ErrorCode;
use App\Enums\Nationality;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetPollActionTest extends TestCase
{
    use RefreshDatabase;

    private GetPollAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new GetPollAction();
    }

    public function testReturnsNotFoundForMissingPoll(): void
    {
        $result = $this->action->__invoke(999999);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testReturnsFeedbackPollWithResponsesCount(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->withFeedbackOptions([['id' => 'c1', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']])
            ->create();

        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            PollResponse::query()->create([
                'user_id' => $user->id,
                'poll_id' => $poll->id,
                'answer' => ['c1'],
                'ip_address' => '127.0.0.1',
            ]);
        }

        $result = $this->action->__invoke($poll->id);

        $this->assertNull($result->getError());
        $this->assertEquals(2, $result->getData()->responses_count);
        $this->assertNull($result->getData()->candidate_users ?? null);
    }

    public function testEagerLoadsWorkplaceAndCreator(): void
    {
        $workplace = Workplace::factory()->create();
        $creator = User::factory()->create();

        $poll = Poll::factory()
            ->workplace($workplace)
            ->create(['created_by' => $creator->id]);

        $result = $this->action->__invoke($poll->id);

        $this->assertTrue($result->getData()->relationLoaded('workplace'));
        $this->assertTrue($result->getData()->relationLoaded('creator'));
        $this->assertEquals($workplace->id, $result->getData()->workplace->id);
        $this->assertEquals($creator->id, $result->getData()->creator->id);
    }

    public function testBuildsCandidateUsersForVotePoll(): void
    {
        $candidate1 = User::factory()->create([
            'first_name' => 'Taro',
            'last_name' => 'Yamada',
            'profile_picture' => 'taro.png',
        ]);
        $candidate2 = User::factory()->create([
            'first_name' => 'Hanako',
            'last_name' => 'Suzuki',
            'profile_picture' => 'hanako.png',
        ]);

        $poll = Poll::factory()
            ->vote()
            ->withVoteOptions([$candidate1->id, $candidate2->id])
            ->create();

        $result = $this->action->__invoke($poll->id);

        $candidateUsers = $result->getData()->candidate_users;

        $this->assertCount(2, $candidateUsers);
        $this->assertEquals($candidate1->id, $candidateUsers[0]['id']);
        $this->assertEquals($candidate1->name, $candidateUsers[0]['name']);
        $this->assertEquals('taro.png', $candidateUsers[0]['profile_picture']);
        $this->assertEquals($candidate2->id, $candidateUsers[1]['id']);
        $this->assertEquals($candidate2->name, $candidateUsers[1]['name']);
    }

    public function testFallsBackToEmptyStringsForDeletedCandidate(): void
    {
        $candidate = User::factory()->create();

        $poll = Poll::factory()
            ->vote()
            ->withVoteOptions([$candidate->id, 999999])
            ->create();

        $result = $this->action->__invoke($poll->id);

        $candidateUsers = $result->getData()->candidate_users;

        $this->assertCount(2, $candidateUsers);
        $this->assertEquals(999999, $candidateUsers[1]['id']);
        $this->assertEquals('', $candidateUsers[1]['name']);
        $this->assertEquals('', $candidateUsers[1]['profile_picture']);
    }

    public function testLoadsTargetUsersForSpecificUsersPoll(): void
    {
        $target = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->specificUsers()->create();
        $poll->targetUsers()->sync([$target->id]);

        $result = $this->action->__invoke($poll->id);

        $this->assertTrue($result->getData()->relationLoaded('targetUsers'));
        $this->assertCount(1, $result->getData()->targetUsers);
        $this->assertEquals($target->id, $result->getData()->targetUsers->first()['id']);
        $this->assertEquals('S001', $result->getData()->targetUsers->first()['staff_no']);
        $this->assertEquals($target->name, $result->getData()->targetUsers->first()['name']);
    }

    public function testTargetUsersUseShortenedNameForSriLankaNationality(): void
    {
        $target = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $target->info->update(['nationality' => Nationality::SriLanka->value]);

        $poll = Poll::factory()->specificUsers()->create();
        $poll->targetUsers()->sync([$target->id]);

        $result = $this->action->__invoke($poll->id);

        $this->assertEquals('THARINDU RAVISHANN', $result->getData()->targetUsers->first()['name']);
    }
}
