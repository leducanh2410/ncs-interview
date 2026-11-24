<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\PermissionName;
use App\Enums\PollTargetType;
use App\Enums\PollType;
use App\Models\Permission;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesRecurringShiftFlow;
use Tests\TestCase;

class RecurringPollAdminTest extends TestCase
{
    use DrivesRecurringShiftFlow;
    use RefreshDatabase;

    private User $admin;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->create();
        foreach ([PermissionName::PollCreate, PermissionName::PollEdit, PermissionName::PollList] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name->value], ['display_name' => $name->value]);
            $this->admin->givePermission($permission, null);
        }

        $this->workplace = Workplace::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testVotePollCannotBeRecurring(): void
    {
        $candidate = User::factory()->create();

        $this->actingAs($this->admin, 'api')
            ->postJson(
                '/manage/polls',
                $this->payload(['type' => PollType::Vote->value, 'options' => [$candidate->id]])
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');
    }

    public function testGlobalAndSpecificUserPollsCannotBeRecurring(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(
                '/manage/polls',
                $this->payload(['target_type' => PollTargetType::GLOBAL->value, 'workplace_id' => null])
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');

        $this->actingAs($this->admin, 'api')
            ->postJson(
                '/manage/polls',
                $this->payload([
                    'target_type' => PollTargetType::SPECIFIC_USERS->value,
                    'workplace_id' => null,
                    'target_users' => [User::factory()->create()->id],
                ])
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');
    }

    public function testRecurringPollIsStoredAsRequiredWithoutExpiry(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/manage/polls', $this->payload(['is_required' => false, 'expires_at' => '2099-12-31']))
            ->assertOk()
            ->assertJsonPath('data.is_recurring', true)
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.expires_at', null);

        $this->actingAs($this->admin, 'api')
            ->getJson('/manage/polls/' . $response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.is_recurring', true);
    }

    public function testOmittedFlagDefaultsToOneTime(): void
    {
        $payload = $this->payload();
        unset($payload['is_recurring']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/manage/polls', $payload)
            ->assertOk()
            ->assertJsonPath('data.is_recurring', false);
    }

    public function testUpdateKeepsRecurringPollRequiredAndWithoutExpiry(): void
    {
        $poll = $this->recurringPoll();

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/manage/polls/{$poll->id}",
                $this->titles() + ['is_required' => false, 'expires_at' => '2099-12-31']
            )
            ->assertOk();

        $poll->refresh();
        $this->assertTrue($poll->is_recurring);
        $this->assertTrue($poll->is_required);
        $this->assertNull($poll->expires_at);
    }

    public function testRecurringCanBeToggledOnAnActivePollWithResponses(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->active()
            ->create([
                'target_type' => PollTargetType::WORKPLACE->value,
                'workplace_id' => $this->workplace->id,
                'is_required' => false,
                'expires_at' => '2099-12-31',
            ]);
        PollResponse::query()->create([
            'user_id' => User::factory()->create()->id,
            'poll_id' => $poll->id,
            'answer' => ['a'],
            'ip_address' => '127.0.0.1',
        ]);

        $this->actingAs($this->admin, 'api')
            ->putJson("/manage/polls/{$poll->id}", $this->titles() + ['is_recurring' => true])
            ->assertOk();
        $this->assertTrue($poll->fresh()->is_recurring);
        $this->assertTrue($poll->fresh()->is_required);

        $this->actingAs($this->admin, 'api')
            ->putJson("/manage/polls/{$poll->id}", $this->titles() + ['is_recurring' => false])
            ->assertOk();
        $this->assertFalse($poll->fresh()->is_recurring);
    }

    public function testMovingARecurringPollAwayFromAWorkplaceRequiresTurningRecurringOff(): void
    {
        $poll = $this->recurringPoll(['is_active' => false]);

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/manage/polls/{$poll->id}",
                $this->titles() + ['target_type' => PollTargetType::GLOBAL->value, 'workplace_id' => null]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_recurring', 'data');

        $this->actingAs($this->admin, 'api')
            ->putJson(
                "/manage/polls/{$poll->id}",
                $this->titles() + [
                    'target_type' => PollTargetType::GLOBAL->value,
                    'workplace_id' => null,
                    'is_recurring' => false,
                ]
            )
            ->assertOk();
        $this->assertFalse($poll->fresh()->is_recurring);
    }

    public function testResponsesAndCountersCanBeFilteredByOccurrence(): void
    {
        $this->travelToDate('2026-09-01 08:00:00');
        $this->setUpRecurringShiftFlow();
        $employee = $this->createRecurringMember($this->workplace);
        $poll = $this->recurringPoll([
            'options' => [
                ['id' => 'first', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['id' => 'second', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
            'result_counters' => ['first' => 0, 'second' => 0],
        ]);

        $first = $this->approveShift($employee, $this->workplace, '2026-09-01');
        $this->actingAs($employee, 'api')
            ->postJson("/user/polls/{$poll->id}/respond", ['answer' => ['first']])
            ->assertOk();
        $this->travelToDate('2026-09-01 19:00:00');
        $this->workShift($first);
        $this->approveAndWorkDays($employee, $this->workplace, '2026-09-02', 9);
        $this->travelToDate('2026-09-11 08:00:00');
        $this->approveShift($employee, $this->workplace, '2026-09-11');
        $this->actingAs($employee, 'api')
            ->postJson("/user/polls/{$poll->id}/respond", ['answer' => ['second']])
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/polls/{$poll->id}")
            ->assertOk()
            ->assertJsonPath('data.available_occurrences', [1, 2])
            ->assertJsonPath('data.result_counters', ['first' => 1, 'second' => 1]);

        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/polls/{$poll->id}?occurrence=2")
            ->assertOk()
            ->assertJsonPath('data.result_counters', ['first' => 0, 'second' => 1]);

        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/polls/{$poll->id}/responses")
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->actingAs($this->admin, 'api')
            ->getJson("/manage/polls/{$poll->id}/responses?occurrence=2")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.occurrence', 2)
            ->assertJsonPath('data.data.0.workplace.name', $this->workplace->name);

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/manage/polls/{$poll->id}/responses?occurrence=2")
            ->assertForbidden();
    }

    private function recurringPoll(array $attributes = []): Poll
    {
        return Poll::factory()
            ->feedback()
            ->active()
            ->create(
                $attributes + [
                    'target_type' => PollTargetType::WORKPLACE->value,
                    'workplace_id' => $this->workplace->id,
                    'is_required' => true,
                    'is_recurring' => true,
                ]
            );
    }

    private function payload(array $overrides = []): array
    {
        return $overrides +
            $this->titles() + [
                'type' => PollType::Feedback->value,
                'target_type' => PollTargetType::WORKPLACE->value,
                'workplace_id' => $this->workplace->id,
                'is_active' => false,
                'is_required' => true,
                'is_recurring' => true,
                'is_multiple' => false,
                'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ];
    }

    private function titles(): array
    {
        return ['title_vi' => 'Phản hồi', 'title_en' => 'Feedback', 'title_ja' => 'フィードバック'];
    }
}
