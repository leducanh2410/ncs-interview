<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\UpdatePollAction;
use App\Enums\ErrorCode;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePollActionTest extends TestCase
{
    use RefreshDatabase;

    public function testUpdatesPollSuccessfully(): void
    {
        $poll = Poll::factory()->create([
            'title_en' => 'Original',
        ]);

        $data = [
            'title_vi' => 'Tiêu đề mới',
            'title_en' => 'New Title',
            'title_ja' => '新しいタイトル',
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertNull($result->getError());
        $this->assertEquals('New Title', $result->getData()->title_en);
    }

    public function testReturnsErrorWhenPollNotFound(): void
    {
        $action = app(UpdatePollAction::class);
        $result = $action->__invoke(99999, ['title_en' => 'Test']);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testIgnoresIsActiveField(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => false,
            'title_en' => 'Original',
        ]);

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, [
            'is_active' => true,
            'title_en' => 'Updated',
        ]);

        $this->assertNull($result->getError());
        $this->assertFalse($result->getData()->is_active);
    }

    public function testPreventsLockedFieldsWhenResponsesExist(): void
    {
        $poll = Poll::factory()->create([
            'options' => [[($id = 1), 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            'is_multiple' => false,
            'max_choice' => 1,
        ]);

        PollResponse::factory()->forPoll($poll)->create();

        $data = [
            'options' => [[($id = 2), 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B']],
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testPreventsMaxChoiceChangeWhenResponsesExist(): void
    {
        $poll = Poll::factory()->create([
            'is_multiple' => true,
            'max_choice' => 2,
        ]);

        PollResponse::factory()->forPoll($poll)->create();

        $data = [
            'max_choice' => 5,
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testPreventsTargetTypeChangeWhenPollIsActive(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => true,
            'target_type' => PollTargetType::SPECIFIC_USERS,
        ]);
        $target = User::factory()->create();
        $poll->targetUsers()->sync([$target->id]);

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, ['target_type' => PollTargetType::GLOBAL->value]);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());

        $poll->refresh();
        $this->assertEquals(PollTargetType::SPECIFIC_USERS, $poll->target_type);
        $this->assertEquals([$target->id], $poll->targetUsers()->pluck('users.id')->all());
    }

    public function testPreventsTargetTypeChangeWhenResponsesExist(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => false,
            'target_type' => PollTargetType::GLOBAL,
        ]);
        PollResponse::factory()->forPoll($poll)->create();

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, ['target_type' => PollTargetType::SPECIFIC_USERS->value]);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertEquals(PollTargetType::GLOBAL, $poll->fresh()->target_type);
    }

    public function testPreventsTargetUsersChangeWhenPollIsActive(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => true,
            'target_type' => PollTargetType::SPECIFIC_USERS,
        ]);
        $original = User::factory()->create();
        $poll->targetUsers()->sync([$original->id]);
        $intruder = User::factory()->create();

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, ['target_users' => [$intruder->id]]);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertEquals([$original->id], $poll->targetUsers()->pluck('users.id')->all());
    }

    public function testAllowsTitleChangeWhenPollIsActive(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => true,
            'title_en' => 'Original',
        ]);

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, ['title_en' => 'Updated']);

        $this->assertNull($result->getError());
        $this->assertEquals('Updated', $result->getData()->title_en);
    }

    public function testAllowsFieldChangesWhenNoResponses(): void
    {
        $poll = Poll::factory()->create([
            'is_multiple' => true,
            'max_choice' => 2,
        ]);

        $data = [
            'is_multiple' => false,
            'max_choice' => 1,
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertNull($result->getError());
        $this->assertFalse($result->getData()->is_multiple);
        $this->assertEquals(1, $result->getData()->max_choice);
    }

    public function testReinitializeResultCountersWhenOptionsChange(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $poll = Poll::factory()->create([
            'type' => PollType::Vote,
            'options' => [$user1->id],
            'result_counters' => [
                (string) $user1->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
            ],
        ]);

        $data = [
            'options' => [$user1->id, $user2->id],
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertNull($result->getError());
        $this->assertArrayHasKey((string) $user2->id, $result->getData()->result_counters);
    }

    public function testForcesMaxChoiceToOneWhenIsMultipleBecomesFalse(): void
    {
        $poll = Poll::factory()->create([
            'is_multiple' => true,
            'max_choice' => 5,
        ]);

        $data = [
            'is_multiple' => false,
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertNull($result->getError());
        $this->assertEquals(1, $result->getData()->max_choice);
    }

    public function testUpdatesFeedbackOptionsAndCounters(): void
    {
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => false,
            'options' => [],
        ]);

        $data = [
            'options' => [
                ['id' => '123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'], // no ID
                ['id' => '123', 'label_vi' => 'C', 'label_en' => 'C', 'label_ja' => 'C'], // duplicate ID
            ],
        ];

        $action = app(UpdatePollAction::class);
        $result = $action->__invoke($poll->id, $data);

        $this->assertNull($result->getError());
        $options = $result->getData()->options;

        $this->assertCount(2, $options);
        $this->assertEquals('123', $options[0]['id']);
        $this->assertNotNull($options[1]['id']);

        $counters = $result->getData()->result_counters;
        $this->assertEquals(0, $counters['123']);
        $this->assertEquals(0, $counters[$options[1]['id']]);
    }

    public function testUpdatesIsMultipleAndMaxChoice(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => false,
            'type' => PollType::Vote,
        ]);

        $action = app(UpdatePollAction::class);

        $result = $action->__invoke($poll->id, [
            'is_multiple' => false,
            'options' => [1, 2, 3],
        ]);
        $this->assertEquals(1, $result->getData()->max_choice);

        $result2 = $action->__invoke($poll->id, [
            'is_multiple' => true,
            'options' => [1, 2, 3],
        ]);
        $this->assertEquals(3, $result2->getData()->max_choice);
    }

    public function testUpdatesSpecificUsers(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => false,
            'target_type' => 'global',
        ]);
        $user = User::factory()->create();

        $action = app(UpdatePollAction::class);
        $action->__invoke($poll->id, [
            'target_type' => 'specific_users',
            'target_users' => [$user->id],
        ]);

        $this->assertTrue($poll->targetUsers->contains($user->id));

        $action->__invoke($poll->id, [
            'target_type' => 'global',
        ]);

        $this->assertEmpty($poll->fresh()->targetUsers);
    }
}
