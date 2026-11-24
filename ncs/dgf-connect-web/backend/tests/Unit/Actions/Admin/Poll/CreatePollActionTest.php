<?php

namespace Tests\Unit\Actions\Admin\Poll;

use App\Actions\Admin\Poll\CreatePollAction;
use App\Actions\Common\Poll\FlagEligibleUsersAction;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Enums\UserPaperStatus;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreatePollActionTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatesFeedbackPollWithUlidOptionIds(): void
    {
        $creator = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Tiêu đề',
            'title_en' => 'Title',
            'title_ja' => 'タイトル',
            'is_active' => false,
            'is_required' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [
                ['label_vi' => 'Tốt', 'label_en' => 'Good', 'label_ja' => '良い'],
                ['label_vi' => 'Xấu', 'label_en' => 'Bad', 'label_ja' => '悪い'],
            ],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertNull($result->getError());
        $this->assertEquals(PollType::Feedback, $result->getData()->type);
        $this->assertCount(2, $result->getData()->options);
        $this->assertArrayHasKey('id', $result->getData()->options[0]);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $result->getData()->options[0]['id']);
    }

    public function testInfersWorkplaceTargetTypeWhenOnlyWorkplaceIdIsGiven(): void
    {
        $creator = User::factory()->create();
        $workplace = Workplace::factory()->create();

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($this->feedbackData(['workplace_id' => $workplace->id]), $creator);

        $this->assertNull($result->getError());
        $this->assertEquals(PollTargetType::WORKPLACE, $result->getData()->target_type);
        $this->assertEquals($workplace->id, $result->getData()->workplace_id);
    }

    public function testLegacyWorkplacePayloadDoesNotFlagEveryEmployee(): void
    {
        Bus::fake();

        $creator = User::factory()->create();
        $workplace = Workplace::factory()->create();

        $member = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S001']);
        $member->workplaces()->attach($workplace);

        $outsider = User::factory()->create(['paper_status' => 'approved', 'staff_no' => 'S002']);

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke(
            $this->feedbackData(['workplace_id' => $workplace->id, 'is_active' => true]),
            $creator
        );

        $this->assertNull($result->getError());
        $this->assertTrue($member->fresh()->has_pending_votes);
        $this->assertFalse($outsider->fresh()->has_pending_votes);
    }

    public function testDefaultsToGlobalTargetTypeWhenNoWorkplaceIdIsGiven(): void
    {
        $creator = User::factory()->create();

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($this->feedbackData(), $creator);

        $this->assertNull($result->getError());
        $this->assertEquals(PollTargetType::GLOBAL, $result->getData()->target_type);
    }

    /**
     * Unlike update, create accepts an explicit null and normalizes it rather than writing it to
     * the non-nullable column. This relies on isset() treating null as absent.
     */
    public function testNormalizesExplicitNullTargetTypeToGlobal(): void
    {
        $creator = User::factory()->create();

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($this->feedbackData(['target_type' => null]), $creator);

        $this->assertNull($result->getError());
        $this->assertEquals(PollTargetType::GLOBAL, $result->getData()->target_type);
    }

    public function testInfersWorkplaceWhenTargetTypeIsExplicitlyNull(): void
    {
        $creator = User::factory()->create();
        $workplace = Workplace::factory()->create();

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke(
            $this->feedbackData(['target_type' => null, 'workplace_id' => $workplace->id]),
            $creator
        );

        $this->assertNull($result->getError());
        $this->assertEquals(PollTargetType::WORKPLACE, $result->getData()->target_type);
    }

    public function testExplicitTargetTypeTakesPrecedenceOverInference(): void
    {
        $creator = User::factory()->create();
        $workplace = Workplace::factory()->create();

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke(
            $this->feedbackData([
                'target_type' => PollTargetType::GLOBAL->value,
                'workplace_id' => $workplace->id,
            ]),
            $creator
        );

        $this->assertNull($result->getError());
        $this->assertEquals(PollTargetType::GLOBAL, $result->getData()->target_type);
    }

    private function feedbackData(array $overrides = []): array
    {
        return array_merge(
            [
                'type' => 'feedback',
                'title_vi' => 'Tiêu đề',
                'title_en' => 'Title',
                'title_ja' => 'タイトル',
                'is_active' => false,
                'is_required' => false,
                'is_multiple' => false,
                'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ],
            $overrides
        );
    }

    public function testCreatesVotePollWithUserIds(): void
    {
        $creator = User::factory()->create();
        $candidate1 = User::factory()->create();
        $candidate2 = User::factory()->create();

        $data = [
            'type' => 'vote',
            'title_vi' => 'Bầu chọn',
            'title_en' => 'Vote',
            'title_ja' => '投票',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [$candidate1->id, $candidate2->id],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertNull($result->getError());
        $this->assertEquals(PollType::Vote, $result->getData()->type);
        $this->assertEquals([$candidate1->id, $candidate2->id], $result->getData()->options);
    }

    public function testInitializesResultCountersForVote(): void
    {
        $creator = User::factory()->create();
        $candidate1 = User::factory()->create();
        $candidate2 = User::factory()->create();

        $data = [
            'type' => 'vote',
            'title_vi' => 'Bầu chọn',
            'title_en' => 'Vote',
            'title_ja' => '投票',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [$candidate1->id, $candidate2->id],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $counters = $result->getData()->result_counters;
        $this->assertEquals(['yes' => 0, 'no' => 0, 'blank' => 0], $counters[(string) $candidate1->id]);
        $this->assertEquals(['yes' => 0, 'no' => 0, 'blank' => 0], $counters[(string) $candidate2->id]);
    }

    public function testInitializesResultCountersForFeedback(): void
    {
        $creator = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [
                ['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $counters = $result->getData()->result_counters;
        $this->assertEquals(0, $counters[$result->getData()->options[0]['id']]);
        $this->assertEquals(0, $counters[$result->getData()->options[1]['id']]);
    }

    public function testForcesMaxChoiceToOneWhenNotMultiple(): void
    {
        $creator = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 5,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertEquals(1, $result->getData()->max_choice);
    }

    public function testActivatesAndFlagsUsersWhenIsActiveTrue(): void
    {
        Bus::fake();

        $creator = User::factory()->create();
        $user = User::factory()->create([
            'paper_status' => UserPaperStatus::Approved->value,
            'staff_no' => 'S001',
            'has_pending_votes' => false,
        ]);

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => true,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $action->__invoke($data, $creator);

        $this->assertTrue($user->fresh()->has_pending_votes);
    }

    public function testSyncsTargetUsersForSpecificUsersPoll(): void
    {
        $creator = User::factory()->create();
        $target1 = User::factory()->create();
        $target2 = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'target_type' => PollTargetType::SPECIFIC_USERS->value,
            'target_users' => [$target1->id, $target2->id],
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertEquals(PollTargetType::SPECIFIC_USERS, $result->getData()->target_type);
        $this->assertEqualsCanonicalizing(
            [$target1->id, $target2->id],
            $result->getData()->targetUsers()->pluck('users.id')->all()
        );
    }

    public function testIgnoresTargetUsersWhenTargetTypeIsNotSpecificUsers(): void
    {
        $creator = User::factory()->create();
        $target = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'target_type' => PollTargetType::GLOBAL->value,
            'target_users' => [$target->id],
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertCount(0, $result->getData()->targetUsers()->get());
    }

    public function testDefaultsTargetTypeToGlobal(): void
    {
        $creator = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertEquals(PollTargetType::GLOBAL, $result->getData()->target_type);
    }

    public function testSkipsDuplicateFeedbackOptionIds(): void
    {
        $creator = User::factory()->create();
        $duplicatedId = (string) Str::ulid();

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'max_choice' => 1,
            'options' => [
                ['id' => $duplicatedId, 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['id' => $duplicatedId, 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertCount(1, $result->getData()->options);
        $this->assertEquals('A', $result->getData()->options[0]['label_en']);
        $this->assertEquals([$duplicatedId => 0], $result->getData()->result_counters);
    }

    public function testDefaultsMaxChoiceToOptionCountWhenMultipleAndMaxChoiceOmitted(): void
    {
        $creator = User::factory()->create();

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => true,
            'options' => [
                ['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                ['label_vi' => 'C', 'label_en' => 'C', 'label_ja' => 'C'],
            ],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertEquals(3, $result->getData()->max_choice);
    }

    public function testPreservesProvidedOptionIds(): void
    {
        $creator = User::factory()->create();
        $existingId = '01JABCD123456789012345678';

        $data = [
            'type' => 'feedback',
            'title_vi' => 'Khảo sát',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [['id' => $existingId, 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ];

        $action = new CreatePollAction(new FlagEligibleUsersAction());
        $result = $action->__invoke($data, $creator);

        $this->assertEquals($existingId, $result->getData()->options[0]['id']);
    }
}
