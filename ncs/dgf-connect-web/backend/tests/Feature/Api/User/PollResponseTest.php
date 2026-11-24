<?php

namespace Tests\Feature\Api\User;

use App\Enums\ErrorCode;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PollResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'staff_no' => 'EMP001',
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $this->user->addRole($role);
    }

    public function testUserCanSubmitFeedbackPollResponse(): void
    {
        $poll = Poll::factory()
            ->active()
            ->create([
                'type' => PollType::Feedback,
                'options' => [
                    ['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                    ['id' => '01HXYZ456', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                ],
                'result_counters' => ['01HXYZ123' => 0, '01HXYZ456' => 0],
            ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
            'device_info' => ['model' => 'iPhone 15', 'os' => 'iOS 18'],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.submitted', true);

        $this->assertDatabaseHas('poll_responses', [
            'poll_id' => $poll->id,
            'user_id' => $this->user->id,
        ]);

        $poll->refresh();
        $this->assertEquals(1, $poll->result_counters['01HXYZ123']);
    }

    public function testUserCanSubmitVotePollResponse(): void
    {
        $candidates = User::factory()->count(2)->create();

        $poll = Poll::factory()
            ->active()
            ->create([
                'type' => PollType::Vote,
                'options' => $candidates->pluck('id')->toArray(),
                'result_counters' => [
                    (string) $candidates[0]->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
                    (string) $candidates[1]->id => ['yes' => 0, 'no' => 0, 'blank' => 0],
                ],
                'is_multiple' => true,
                'max_choice' => 2,
            ]);

        $answer = [
            (string) $candidates[0]->id => 1,
            (string) $candidates[1]->id => -1,
        ];

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => $answer,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.submitted', true);

        $poll->refresh();
        $this->assertEquals(1, $poll->result_counters[(string) $candidates[0]->id]['yes']);
        $this->assertEquals(1, $poll->result_counters[(string) $candidates[1]->id]['no']);
    }

    public function testUserCannotSubmitToInactivePoll(): void
    {
        $poll = Poll::factory()->create([
            'is_active' => false,
            'type' => PollType::Feedback,
            'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(422);
    }

    public function testUserCannotSubmitToExpiredPoll(): void
    {
        $poll = Poll::factory()
            ->active()
            ->expired()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(422);
    }

    public function testUserCannotSubmitTwiceToSamePoll(): void
    {
        $poll = Poll::factory()
            ->active()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
                'result_counters' => ['01HXYZ123' => 0],
            ]);

        PollResponse::factory()->forPoll($poll)->forUser($this->user)->create();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(422);
    }

    public function testUserWithoutStaffNoCannotSubmitToGlobalPoll(): void
    {
        $userWithoutStaffNo = User::factory()->create([
            'staff_no' => null,
            'paper_status' => UserPaperStatus::Approved->value,
        ]);
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $userWithoutStaffNo->addRole($role);

        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $response = $this->actingAs($userWithoutStaffNo, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(403);
    }

    public function testUserCannotSubmitToWorkplacePollWithoutMembership(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->active()
            ->workplace($workplace)
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(403);
    }

    public function testUserCanSubmitToWorkplacePollWithMembership(): void
    {
        $workplace = Workplace::factory()->create();
        DB::table('workplace_users')->insert([
            'user_id' => $this->user->id,
            'workplace_id' => $workplace->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poll = Poll::factory()
            ->active()
            ->workplace($workplace)
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
                'result_counters' => ['01HXYZ123' => 0],
            ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.submitted', true);
    }

    public function testUserCannotSubmitDuplicateFeedbackChoices(): void
    {
        $poll = Poll::factory()
            ->active()
            ->create([
                'type' => PollType::Feedback,
                'options' => [
                    ['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                    ['id' => '01HXYZ456', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                ],
                'result_counters' => ['01HXYZ123' => 0, '01HXYZ456' => 0],
                'is_multiple' => true,
                'max_choice' => 2,
            ]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123', '01HXYZ123'],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('poll_responses', 0);
        $this->assertSame(0, $poll->fresh()->result_counters['01HXYZ123']);
    }

    // Auth payload tests (Task 12)
    public function testAuthCheckReturnsPendingVotesForEmployeeWithPendingPolls(): void
    {
        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'title_vi' => 'Khao sat',
                'title_en' => 'Survey',
                'title_ja' => '調査',
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ]);

        $this->user->update(['has_pending_votes' => true]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                'pending_votes' => [
                    '*' => [
                        'id',
                        'type',
                        'title_vi',
                        'title_en',
                        'title_ja',
                        'is_required',
                        'is_multiple',
                        'max_choice',
                        'options',
                    ],
                ],
            ],
        ]);
    }

    public function testAuthCheckReturnsEmptyPendingVotesWhenFlagIsFalse(): void
    {
        $this->user->update(['has_pending_votes' => false]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200)->assertJsonPath('data.pending_votes', []);
    }

    public function testAuthCheckResetsFlagWhenNoActualPendingPolls(): void
    {
        // User has flag set but all polls are inactive
        $this->user->update(['has_pending_votes' => true]);

        // No active polls exist
        Poll::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200)->assertJsonPath('data.pending_votes', []);

        // Flag should be reset
        $this->assertFalse($this->user->fresh()->has_pending_votes);
    }

    public function testAuthCheckExcludesRespondedPollsFromPending(): void
    {
        $poll = Poll::factory()
            ->active()
            ->global()
            ->create([
                'type' => PollType::Feedback,
                'options' => [['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
                'result_counters' => ['01HXYZ123' => 0],
            ]);

        // User has already responded
        PollResponse::factory()->forPoll($poll)->forUser($this->user)->create();

        $this->user->update(['has_pending_votes' => true]);

        $response = $this->actingAs($this->user, 'api')->getJson('/auth/check');

        $response->assertStatus(200)->assertJsonPath('data.pending_votes', []);
    }

    public function testRejectsMissingAnswer(): void
    {
        $poll = $this->activeFeedbackPoll();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", []);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsNonArrayVoteAnswer(): void
    {
        $poll = $this->activeVotePoll();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => 'not-an-object',
        ]);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsVoteAnswerMissingCandidates(): void
    {
        $poll = $this->activeVotePoll();
        $candidateIds = $poll->options;

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => [(string) $candidateIds[0] => 1],
        ]);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsVoteAnswerWithInvalidValue(): void
    {
        $poll = $this->activeVotePoll();
        [$first, $second] = $poll->options;

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => [(string) $first => 7, (string) $second => 0],
        ]);

        $this->assertValidationErrors($response, ["answer.{$first}"]);
    }

    public function testRejectsVoteAnswerExceedingMaxChoice(): void
    {
        $poll = $this->activeVotePoll(['is_multiple' => false, 'max_choice' => 1]);
        [$first, $second] = $poll->options;

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => [(string) $first => 1, (string) $second => 1],
        ]);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsNonArrayFeedbackAnswer(): void
    {
        $poll = $this->activeFeedbackPoll();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => 'not-an-array',
        ]);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsUnknownFeedbackChoice(): void
    {
        $poll = $this->activeFeedbackPoll();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['UNKNOWN'],
        ]);

        $this->assertValidationErrors($response, ['answer.0']);
    }

    public function testRejectsMultipleChoicesOnSingleChoiceFeedbackPoll(): void
    {
        $poll = $this->activeFeedbackPoll(['is_multiple' => false, 'max_choice' => 1]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123', '01HXYZ456'],
        ]);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsFeedbackAnswerExceedingMaxChoice(): void
    {
        $poll = $this->activeFeedbackPoll(['is_multiple' => true, 'max_choice' => 1]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123', '01HXYZ456'],
        ]);

        $this->assertValidationErrors($response, ['answer']);
    }

    public function testRejectsOversizedDeviceInfo(): void
    {
        $poll = $this->activeFeedbackPoll();

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
            'device_info' => ['model' => str_repeat('a', 101)],
        ]);

        $this->assertValidationErrors($response, ['device_info.model']);
    }

    public function testTargetedUserCanSubmitToSpecificUsersPoll(): void
    {
        $poll = $this->activeFeedbackPoll();
        $poll->update(['target_type' => PollTargetType::SPECIFIC_USERS->value]);
        $poll->targetUsers()->sync([$this->user->id]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.submitted', true);
    }

    public function testUntargetedUserCannotSubmitToSpecificUsersPoll(): void
    {
        $poll = $this->activeFeedbackPoll();
        $poll->update(['target_type' => PollTargetType::SPECIFIC_USERS->value]);
        $poll->targetUsers()->sync([User::factory()->create()->id]);

        $response = $this->actingAs($this->user, 'api')->postJson("/user/polls/{$poll->id}/respond", [
            'answer' => ['01HXYZ123'],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('poll_responses', 0);
    }

    /**
     * Validation failures are rendered as {"error": "E_UNPROCESSABLE_ENTITY", "data": {field: [messages]}}.
     */
    private function assertValidationErrors(TestResponse $response, array $keys): void
    {
        $response->assertStatus(422)->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $response->json('data') ?? []);
        }
    }

    private function activeFeedbackPoll(array $overrides = []): Poll
    {
        return Poll::factory()
            ->active()
            ->global()
            ->create(
                array_merge(
                    [
                        'type' => PollType::Feedback,
                        'is_multiple' => false,
                        'max_choice' => 1,
                        'options' => [
                            ['id' => '01HXYZ123', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                            ['id' => '01HXYZ456', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                        ],
                        'result_counters' => ['01HXYZ123' => 0, '01HXYZ456' => 0],
                    ],
                    $overrides
                )
            );
    }

    private function activeVotePoll(array $overrides = []): Poll
    {
        $candidates = User::factory()->count(2)->create();
        $candidateIds = $candidates->pluck('id')->toArray();

        return Poll::factory()
            ->active()
            ->global()
            ->create(
                array_merge(
                    [
                        'type' => PollType::Vote,
                        'is_multiple' => true,
                        'max_choice' => 2,
                        'options' => $candidateIds,
                        'result_counters' => [
                            (string) $candidateIds[0] => ['yes' => 0, 'no' => 0, 'blank' => 0],
                            (string) $candidateIds[1] => ['yes' => 0, 'no' => 0, 'blank' => 0],
                        ],
                    ],
                    $overrides
                )
            );
    }
}
