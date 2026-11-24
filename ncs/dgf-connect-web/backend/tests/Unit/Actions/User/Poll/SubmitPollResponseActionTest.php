<?php

namespace Tests\Unit\Actions\User\Poll;

use App\Actions\User\Poll\SubmitPollResponseAction;
use App\Enums\ErrorCode;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmitPollResponseActionTest extends TestCase
{
    use RefreshDatabase;

    private SubmitPollResponseAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(SubmitPollResponseAction::class);
    }

    public function testSubmitsFeedbackResponseSuccessfully(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [
                ['id' => '01JABC', 'label_vi' => 'Tốt', 'label_en' => 'Good', 'label_ja' => '良い'],
                ['id' => '01JDEF', 'label_vi' => 'Xấu', 'label_en' => 'Bad', 'label_ja' => '悪い'],
            ],
            'result_counters' => ['01JABC' => 0, '01JDEF' => 0],
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertNull($result->getError());
        $this->assertTrue($result->getData()['submitted']);

        $savedResponse = PollResponse::where('poll_id', $poll->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($savedResponse);
        $this->assertEquals(['01JABC'], $savedResponse->answer);
        $this->assertEquals(1, $poll->fresh()->result_counters['01JABC']);
    }

    public function testSubmitsVoteResponseSuccessfully(): void
    {
        $user1 = User::factory()->create(['first_name' => 'A', 'last_name' => 'User', 'staff_no' => 'S001']);
        $user2 = User::factory()->create(['first_name' => 'B', 'last_name' => 'User']);

        $poll = Poll::factory()->create([
            'type' => PollType::Vote,
            'is_active' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [$user1->id, $user2->id],
            'result_counters' => [
                (string) $user1->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
                (string) $user2->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
            ],
        ]);

        $answer = [(string) $user1->id => 1, (string) $user2->id => -1];
        $result = $this->action->__invoke($user1, $poll->id, $answer, '127.0.0.1', null);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('poll_responses', [
            'poll_id' => $poll->id,
            'user_id' => $user1->id,
        ]);
        $this->assertEquals(1, $poll->fresh()->result_counters[(string) $user1->id]['yes']);
        $this->assertEquals(1, $poll->fresh()->result_counters[(string) $user2->id]['no']);
    }

    public function testRejectsInactivePoll(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create(['is_active' => false]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::PollInactive, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testRejectsExpiredPoll(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::PollExpired, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testRejectsUserWithoutStaffNo(): void
    {
        $user = User::factory()->create(['staff_no' => null]);
        $poll = Poll::factory()->create(['is_active' => true]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
    }

    public function testRejectsUserNotInWorkplace(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()->create([
            'is_active' => true,
            'target_type' => PollTargetType::WORKPLACE->value,
            'workplace_id' => $workplace->id,
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
        $this->assertEquals(403, $result->getStatus());
    }

    public function testRejectsDuplicateResponse(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['id' => '01JABC', 'label_vi' => 'Tốt', 'label_en' => 'Good', 'label_ja' => '良い']],
            'result_counters' => ['01JABC' => 0],
        ]);

        PollResponse::factory()
            ->forPoll($poll)
            ->forUser($user)
            ->create(['answer' => ['01JABC']]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testRejectsVoteAnswerExceedingMaxChoice(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Vote,
            'is_active' => true,
            'is_multiple' => true,
            'max_choice' => 1,
            'options' => [$user1->id, $user2->id],
            'result_counters' => [
                (string) $user1->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
                (string) $user2->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
            ],
        ]);

        $answer = [(string) $user1->id => 1, (string) $user2->id => 1];
        $result = $this->action->__invoke($user, $poll->id, $answer, '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testRejectsFeedbackAnswerWhenMultipleSelectionRequired(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [
                ['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['id' => '01JDEF', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
            'result_counters' => ['01JABC' => 0, '01JDEF' => 0],
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC', '01JDEF'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testRejectsInvalidChoiceId(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            'result_counters' => ['01JABC' => 0],
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['INVALID_ID'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
    }

    public function testRejectsDuplicateFeedbackChoices(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [
                ['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['id' => '01JDEF', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
            'result_counters' => ['01JABC' => 0, '01JDEF' => 0],
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC', '01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertDatabaseCount('poll_responses', 0);
        $this->assertSame(0, $poll->fresh()->result_counters['01JABC']);
    }

    public function testStoresIpAddressAndDeviceInfo(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            'result_counters' => ['01JABC' => 0],
        ]);

        $deviceInfo = ['model' => 'iPhone 15', 'os' => 'iOS 18', 'app_version' => '2.1.0'];
        $this->action->__invoke($user, $poll->id, ['01JABC'], '192.168.1.1', $deviceInfo);

        $this->assertDatabaseHas('poll_responses', [
            'poll_id' => $poll->id,
            'user_id' => $user->id,
            'ip_address' => '192.168.1.1',
        ]);
    }

    public function testClearsHasPendingVotesAfterSubmission(): void
    {
        $user = User::factory()->create([
            'paper_status' => 'approved',
            'staff_no' => 'S001',
            'has_pending_votes' => true,
        ]);
        $poll = Poll::factory()->create([
            'type' => PollType::Feedback,
            'is_active' => true,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            'result_counters' => ['01JABC' => 0],
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertNull($result->getError());
        $this->assertFalse($result->getData()['has_pending_votes']);
    }

    public function testRejectsIfSpecificUserNotTargeted(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'is_active' => true,
            'target_type' => PollTargetType::SPECIFIC_USERS->value,
            'options' => [1, 2],
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 1], '127.0.0.1', null);
        $this->assertEquals(ErrorCode::Forbidden, $result->getError());
    }

    public function testRejectsInvalidOptionsFormat(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()->create([
            'is_active' => true,
            'target_type' => 'global',
            'options' => 'invalid_string', // Not an array
        ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 1], '127.0.0.1', null);
        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.invalid_options'), $result->getData()['message']);
    }

    public function testRejectsMissingCandidatesInAnswer(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->vote()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'options' => [1, 2],
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 1], '127.0.0.1', null); // Missing '2'
        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.missing_candidates'), $result->getData()['message']);
    }

    public function testRejectsInvalidVoteValues(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->vote()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'options' => [1, 2],
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 5, '2' => 0], '127.0.0.1', null); // 5 is invalid
        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.invalid_vote_value'), $result->getData()['message']);
    }

    public function testRejectsInvalidFeedbackOption(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->feedback()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'options' => [['id' => '01JABC', 'label_vi' => 'A']],
                'is_multiple' => false,
                'max_choice' => 1,
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['INVALID_ID'], '127.0.0.1', null);
        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.invalid_choice'), $result->getData()['message']);
    }

    public function testReturnsNotFoundForMissingPoll(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);

        $result = $this->action->__invoke($user, 999999, ['01JABC'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
    }

    public function testRejectsVotePollWithInvalidOptionsFormat(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->vote()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'options' => 'invalid_string',
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 1], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.invalid_options'), $result->getData()['message']);
    }

    public function testRejectsEmptyFeedbackAnswer(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->feedback()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'options' => [['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $result = $this->action->__invoke($user, $poll->id, [], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.empty_answer'), $result->getData()['message']);
    }

    public function testRejectsFeedbackAnswerExceedingMaxChoice(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->feedback()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'is_multiple' => true,
                'max_choice' => 1,
                'options' => [
                    ['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                    ['id' => '01JDEF', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                ],
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC', '01JDEF'], '127.0.0.1', null);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(__('poll.exceeds_max_choice'), $result->getData()['message']);
    }

    public function testIncrementsVoteCountersForYesNoAndBlank(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->vote()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'is_multiple' => true,
                'max_choice' => 3,
                'options' => [1, 2, 3],
                'result_counters' => [
                    '1' => ['yes' => 0, 'no' => 0, 'blank' => 0],
                    '2' => ['yes' => 0, 'no' => 0, 'blank' => 0],
                    '3' => ['yes' => 0, 'no' => 0, 'blank' => 0],
                ],
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 1, '2' => -1, '3' => 0], '127.0.0.1', null);

        $this->assertNull($result->getError());

        $counters = $poll->fresh()->result_counters;
        $this->assertEquals(['yes' => 1, 'no' => 0, 'blank' => 0], $counters['1']);
        $this->assertEquals(['yes' => 0, 'no' => 1, 'blank' => 0], $counters['2']);
        $this->assertEquals(['yes' => 0, 'no' => 0, 'blank' => 1], $counters['3']);
    }

    public function testInitializesMissingVoteCounterKeys(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->vote()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'options' => [1],
                'result_counters' => [],
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['1' => 1], '127.0.0.1', null);

        $this->assertNull($result->getError());
        $this->assertEquals(['yes' => 1, 'no' => 0, 'blank' => 0], $poll->fresh()->result_counters['1']);
    }

    public function testInitializesMissingFeedbackCounterKeys(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->feedback()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'is_multiple' => false,
                'max_choice' => 1,
                'options' => [['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
                'result_counters' => [],
            ]);

        $result = $this->action->__invoke($user, $poll->id, ['01JABC'], '127.0.0.1', null);

        $this->assertNull($result->getError());
        $this->assertEquals(1, $poll->fresh()->result_counters['01JABC']);
    }

    public function testPersistsIpAddressAndDeviceInfo(): void
    {
        $user = User::factory()->create(['staff_no' => 'S001']);
        $poll = Poll::factory()
            ->feedback()
            ->create([
                'is_active' => true,
                'target_type' => 'global',
                'is_multiple' => false,
                'max_choice' => 1,
                'options' => [['id' => '01JABC', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
                'result_counters' => ['01JABC' => 0],
            ]);

        $deviceInfo = ['model' => 'iPhone 15', 'os' => 'iOS 18', 'app_version' => '1.2.3'];

        $this->action->__invoke($user, $poll->id, ['01JABC'], '203.0.113.7', $deviceInfo);

        $response = PollResponse::query()->where('poll_id', $poll->id)->firstOrFail();

        $this->assertEquals('203.0.113.7', $response->ip_address);
        $this->assertEquals($deviceInfo, $response->device_info);
        $this->assertEquals(['01JABC'], $response->answer);
    }
}
