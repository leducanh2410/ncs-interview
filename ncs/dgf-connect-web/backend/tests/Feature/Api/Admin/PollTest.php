<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\PollTargetType;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Poll;
use App\Models\PollResponse;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PollTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPollPermissions();

        $this->admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $this->admin->addRole($role);
    }

    protected function seedPollPermissions(): void
    {
        foreach (
            [
                PermissionName::PollList->value,
                PermissionName::PollCreate->value,
                PermissionName::PollEdit->value,
                PermissionName::PollDelete->value,
            ]
            as $permissionName
        ) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }

        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('name', [
                    PermissionName::PollList->value,
                    PermissionName::PollCreate->value,
                    PermissionName::PollEdit->value,
                    PermissionName::PollDelete->value,
                ])
                ->pluck('id')
                ->all()
        );
    }

    public function testAdminCanListPolls(): void
    {
        Poll::factory()
            ->count(3)
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/polls');

        $response->assertStatus(200)->assertJsonCount(3, 'data.data');
    }

    public function testAdminCanFilterPollsByType(): void
    {
        Poll::factory()
            ->vote()
            ->create(['created_by' => $this->admin->id]);
        Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/polls?type=vote');

        $response->assertStatus(200)->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.type', 'vote');
    }

    public function testAdminCanCreateFeedbackPoll(): void
    {
        $pollData = [
            'type' => 'feedback',
            'target_type' => 'global',
            'title_vi' => 'Khao sat y kien',
            'title_en' => 'Opinion Survey',
            'title_ja' => '意見調査',
            'description_vi' => 'Mo ta',
            'description_en' => 'Description',
            'description_ja' => '説明',
            'is_active' => false,
            'is_required' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [
                ['label_vi' => 'Lua chon 1', 'label_en' => 'Choice 1', 'label_ja' => '選択肢1'],
                ['label_vi' => 'Lua chon 2', 'label_en' => 'Choice 2', 'label_ja' => '選択肢2'],
            ],
        ];

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls', $pollData);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'feedback')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_multiple', true)
            ->assertJsonPath('data.max_choice', 2);

        $this->assertDatabaseHas('polls', [
            'type' => 'feedback',
            'title_en' => 'Opinion Survey',
            'created_by' => $this->admin->id,
        ]);
    }

    public function testAdminCanCreateFeedbackPollWithNepaliAndSinhalaContent(): void
    {
        $pollData = [
            'type' => 'feedback',
            'target_type' => 'global',
            'title_vi' => 'Khao sat y kien',
            'title_en' => 'Opinion Survey',
            'title_ja' => '意見調査',
            'title_ne' => 'रायसर्वेक्षण',
            'title_si' => 'මත විමසුම',
            'description_vi' => 'Mo ta',
            'description_en' => 'Description',
            'description_ja' => '説明',
            'description_ne' => 'विवरण',
            'description_si' => 'විස්තරය',
            'is_active' => false,
            'is_required' => true,
            'is_multiple' => false,
            'options' => [
                [
                    'label_vi' => 'Lua chon 1',
                    'label_en' => 'Choice 1',
                    'label_ja' => '選択肢1',
                    'label_ne' => 'विकल्प १',
                    'label_si' => 'තේරීම 1',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls', $pollData);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.title_ne', 'रायसर्वेक्षण')
            ->assertJsonPath('data.title_si', 'මත විමසුම')
            ->assertJsonPath('data.description_ne', 'विवरण')
            ->assertJsonPath('data.description_si', 'විස්තරය')
            ->assertJsonPath('data.options.0.label_ne', 'विकल्प १')
            ->assertJsonPath('data.options.0.label_si', 'තේරීම 1');

        $this->assertDatabaseHas('polls', [
            'title_ne' => 'रायसर्वेक्षण',
            'title_si' => 'මත විමසුම',
        ]);
    }

    public function testAdminCanCreateVotePoll(): void
    {
        $candidates = User::factory()->count(3)->create();

        $pollData = [
            'type' => 'vote',
            'target_type' => 'global',
            'title_vi' => 'Bau cu',
            'title_en' => 'Election',
            'title_ja' => '選挙',
            'is_active' => false,
            'is_required' => true,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => $candidates->pluck('id')->toArray(),
        ];

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls', $pollData);

        $response->assertStatus(200)->assertJsonPath('data.type', 'vote')->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('polls', [
            'type' => 'vote',
            'title_en' => 'Election',
        ]);
    }

    public function testAdminCanUpdatePoll(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'Updated Title',
            'title_en' => 'Updated Title',
            'title_ja' => '更新タイトル',
            'is_required' => true,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.title_en', 'Updated Title')
            ->assertJsonPath('data.is_required', true);
    }

    public function testAdminCanUpdatePollWithNepaliAndSinhalaContent(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'Updated Title',
            'title_en' => 'Updated Title',
            'title_ja' => '更新タイトル',
            'title_ne' => 'अद्यावधिक शीर्षक',
            'title_si' => 'යාවත්කාලීන මාතෘකාව',
            'description_ne' => 'विवरण',
            'description_si' => 'විස්තරය',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.title_ne', 'अद्यावधिक शीर्षक')
            ->assertJsonPath('data.title_si', 'යාවත්කාලීන මාතෘකාව')
            ->assertJsonPath('data.description_ne', 'विवरण')
            ->assertJsonPath('data.description_si', 'විස්තරය');
    }

    public function testAdminCannotUpdateLockedFieldsAfterResponses(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id]);
        PollResponse::factory()->forPoll($poll)->create();

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'Updated Title',
            'title_en' => 'Updated Title',
            'title_ja' => '更新タイトル',
            'is_required' => true,
            'options' => [],
        ]);

        $response->assertStatus(422);
    }

    public function testAdminCanActivatePoll(): void
    {
        $poll = Poll::factory()->create([
            'created_by' => $this->admin->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/activate");

        $response->assertStatus(200)->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('polls', [
            'id' => $poll->id,
            'is_active' => true,
        ]);
    }

    public function testAdminCanDeactivatePoll(): void
    {
        $poll = Poll::factory()->create([
            'created_by' => $this->admin->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/deactivate");

        $response->assertStatus(200)->assertJsonPath('data.is_active', false);
    }

    public function testAdminCanDeletePollWithoutResponses(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/manage/polls/{$poll->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('polls', ['id' => $poll->id]);
    }

    public function testAdminCannotDeletePollWithResponses(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id]);
        PollResponse::factory()->forPoll($poll)->create();

        $response = $this->actingAs($this->admin, 'api')->deleteJson("/manage/polls/{$poll->id}");

        $response->assertStatus(422);

        $this->assertDatabaseHas('polls', ['id' => $poll->id]);
    }

    public function testAdminCanGetPollResponses(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id]);
        PollResponse::factory()->count(3)->forPoll($poll)->create();

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/polls/{$poll->id}/responses");

        $response->assertStatus(200)->assertJsonCount(3, 'data.data');
    }

    public function testUnauthorizedUserCannotAccessPolls(): void
    {
        $user = User::factory()->create();
        $userRole = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
        $user->addRole($userRole);

        $response = $this->actingAs($user, 'api')->getJson('/manage/polls');

        $response->assertStatus(403);
    }

    public function testAdminCanFilterPollsByWorkplaceId(): void
    {
        $workplace = Workplace::factory()->create();
        $target = Poll::factory()
            ->workplace($workplace)
            ->create(['created_by' => $this->admin->id]);
        Poll::factory()
            ->global()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/polls?workplace_id={$workplace->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $target->id);
    }

    public function testAdminCanFilterPollsByIsActive(): void
    {
        $active = Poll::factory()
            ->active()
            ->create(['created_by' => $this->admin->id]);
        Poll::factory()->create(['created_by' => $this->admin->id, 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/polls?is_active=1');

        $response->assertStatus(200)->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $active->id);
    }

    public function testAdminCanShowFeedbackPollDetail(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->withFeedbackOptions([['id' => 'c1', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']])
            ->create(['created_by' => $this->admin->id]);

        PollResponse::factory()->forPoll($poll)->create();

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/polls/{$poll->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.id', $poll->id)
            ->assertJsonPath('data.type', 'feedback')
            ->assertJsonPath('data.responses_count', 1);
    }

    public function testAdminCanShowVotePollDetailWithCandidateUsers(): void
    {
        $candidate = User::factory()->create();

        $poll = Poll::factory()
            ->vote()
            ->withVoteOptions([$candidate->id])
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/polls/{$poll->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.candidate_users.0.id', $candidate->id)
            ->assertJsonPath('data.candidate_users.0.name', $candidate->name);
    }

    public function testAdminCanShowSpecificUsersPollWithTargets(): void
    {
        $target = User::factory()->create();
        $poll = Poll::factory()
            ->specificUsers()
            ->create(['created_by' => $this->admin->id]);
        $poll->targetUsers()->sync([$target->id]);

        $response = $this->actingAs($this->admin, 'api')->getJson("/manage/polls/{$poll->id}");

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.scope', 'specific_users')
            ->assertJsonPath('data.target_users.0.id', $target->id);
    }

    public function testShowReturnsNotFoundForMissingPoll(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/polls/999999');

        $response->assertStatus(404);
    }

    public function testAdminCanCreateSpecificUsersPoll(): void
    {
        $target = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls', [
            'type' => 'feedback',
            'target_type' => 'specific_users',
            'target_users' => [$target->id],
            'title_vi' => 'Khao sat',
            'title_en' => 'Survey',
            'title_ja' => '調査',
            'is_active' => false,
            'is_required' => false,
            'is_multiple' => false,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.scope', 'specific_users');

        $this->assertDatabaseHas('poll_targets', [
            'poll_id' => $response->json('data.id'),
            'user_id' => $target->id,
        ]);
    }

    public function testCreateRejectsUnknownTargetType(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'target_type' => 'everyone',
            ])
        );

        $this->assertValidationErrors($response, ['target_type']);
    }

    public function testCreateRejectsWorkplaceScopeWithoutWorkplaceId(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'target_type' => 'workplace',
                'workplace_id' => null,
            ])
        );

        $this->assertValidationErrors($response, ['workplace_id']);
        $this->assertEquals(__('poll.workplace_required'), $response->json('data.workplace_id.0'));
    }

    public function testCreateAcceptsGlobalScopeWithoutWorkplaceId(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'target_type' => 'global',
                'workplace_id' => null,
            ])
        );

        $response->assertStatus(200)->assertJsonPath('data.scope', 'global');
    }

    public function testUpdateRejectsWorkplaceScopeWithoutWorkplaceId(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => 'workplace',
            'workplace_id' => null,
        ]);

        $this->assertValidationErrors($response, ['workplace_id']);
    }

    public function testCreateRejectsSpecificUsersScopeWithEmptyTargetUsers(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'target_type' => 'specific_users',
                'target_users' => [],
            ])
        );

        $this->assertValidationErrors($response, ['target_users']);
    }

    public function testCreateRejectsSpecificUsersScopeWhenTargetUsersOmitted(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload(['target_type' => 'specific_users'])
        );

        $this->assertValidationErrors($response, ['target_users']);
    }

    /**
     * The frontend sends target_users: [] for global and workplace polls; only specific_users
     * requires recipients.
     */
    public function testCreateAllowsEmptyTargetUsersForGlobalScope(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'target_type' => 'global',
                'target_users' => [],
            ])
        );

        $response->assertStatus(200)->assertJsonPath('data.scope', 'global');
    }

    public function testUpdateRejectsSpecificUsersScopeWithEmptyTargetUsers(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'global']);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => 'specific_users',
            'target_users' => [],
        ]);

        $this->assertValidationErrors($response, ['target_users']);
        $this->assertEquals(PollTargetType::GLOBAL, $poll->fresh()->target_type);
    }

    /**
     * Switching to specific_users without naming recipients would leave poll_targets empty,
     * producing an active poll nobody can see.
     */
    public function testUpdateRejectsSwitchingToSpecificUsersWithoutTargetUsers(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'global']);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => 'specific_users',
        ]);

        $this->assertValidationErrors($response, ['target_users']);
        $this->assertEquals(PollTargetType::GLOBAL, $poll->fresh()->target_type);
    }

    /**
     * An existing specific_users poll that keeps its recipients must still be editable without
     * resending the full target_users list.
     */
    public function testUpdateAllowsSpecificUsersPollWhenTargetUsersOmitted(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'specific_users']);
        $poll->targetUsers()->sync([User::factory()->create()->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'Updated',
            'title_ja' => 'T',
            'target_type' => 'specific_users',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.title_en', 'Updated');
    }

    /**
     * Omitting workplace_id entirely must be rejected just like sending it as null, otherwise
     * the poll ends up target_type=workplace with workplace_id=null.
     */
    public function testUpdateRejectsWorkplaceScopeWhenWorkplaceIdIsOmitted(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'global', 'workplace_id' => null]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => 'workplace',
        ]);

        $this->assertValidationErrors($response, ['workplace_id']);
        $poll->refresh();
        $this->assertEquals(PollTargetType::GLOBAL, $poll->target_type);
        $this->assertNull($poll->workplace_id);
    }

    public function testUpdateRejectsNullTargetType(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'global']);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => null,
        ]);

        $this->assertValidationErrors($response, ['target_type']);
        $this->assertEquals(PollTargetType::GLOBAL, $poll->fresh()->target_type);
    }

    public function testUpdateKeepsTargetTypeWhenOmitted(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->feedback()
            ->workplace($workplace)
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'Updated',
            'title_ja' => 'T',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.title_en', 'Updated');
        $poll->refresh();
        $this->assertEquals(PollTargetType::WORKPLACE, $poll->target_type);
        $this->assertEquals($workplace->id, $poll->workplace_id);
    }

    public function testUpdateRejectsWorkplaceIdOnNonWorkplacePoll(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'global', 'workplace_id' => null]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'workplace_id' => $workplace->id,
        ]);

        $this->assertValidationErrors($response, ['workplace_id']);
        $poll->refresh();
        $this->assertEquals(PollTargetType::GLOBAL, $poll->target_type);
        $this->assertNull($poll->workplace_id);
    }

    /**
     * The mirror case: target_type is omitted, the poll is already a workplace poll, and the
     * client nulls out workplace_id.
     */
    public function testUpdateRejectsNullingWorkplaceIdOnWorkplacePoll(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->feedback()
            ->workplace($workplace)
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'workplace_id' => null,
        ]);

        $this->assertValidationErrors($response, ['workplace_id']);
        $this->assertEquals($workplace->id, $poll->fresh()->workplace_id);
    }

    /**
     * Keeping an existing workplace poll's scope while editing other fields must still work,
     * even though workplace_id is not resent.
     */
    public function testUpdateAllowsWorkplaceScopeWhenWorkplaceIdIsAlreadySet(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->feedback()
            ->workplace($workplace)
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'Updated',
            'title_ja' => 'T',
            'target_type' => 'workplace',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.title_en', 'Updated');
        $this->assertEquals($workplace->id, $poll->fresh()->workplace_id);
    }

    /**
     * An active poll locks the whole targeting group, so the frontend omits target_type,
     * workplace_id and target_users. Editing the remaining fields must still work.
     */
    public function testUpdateAllowsEditingActiveWorkplacePollWithoutResendingTargeting(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->feedback()
            ->active()
            ->workplace($workplace)
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'Updated',
            'title_en' => 'Updated',
            'title_ja' => 'Updated',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.title_en', 'Updated');
        $this->assertEquals($workplace->id, $poll->fresh()->workplace_id);
    }

    /**
     * Re-scoping a running poll would leave poll_targets, has_pending_votes and the already
     * collected responses out of sync with the new audience.
     */
    public function testUpdateRejectsTargetTypeChangeOnActivePoll(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->active()
            ->create(['created_by' => $this->admin->id, 'target_type' => 'specific_users']);
        $target = User::factory()->create();
        $poll->targetUsers()->sync([$target->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'Updated',
            'title_en' => 'Updated',
            'title_ja' => 'Updated',
            'target_type' => 'global',
        ]);

        $response->assertStatus(422);
        $this->assertEquals(PollTargetType::SPECIFIC_USERS, $poll->fresh()->target_type);
        $this->assertEquals([$target->id], $poll->targetUsers()->pluck('users.id')->all());
    }

    public function testCreateRejectsUnknownTargetUser(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'target_type' => 'specific_users',
                'target_users' => [999999],
            ])
        );

        $this->assertValidationErrors($response, ['target_users.0']);
    }

    public function testCreateRequiresTargetType(): void
    {
        $payload = $this->feedbackPayload();
        unset($payload['target_type']);

        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls', $payload);

        $this->assertValidationErrors($response, ['target_type']);
    }

    public function testCreateRejectsNullTargetType(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload(['target_type' => null])
        );

        $this->assertValidationErrors($response, ['target_type']);
    }

    public function testCreateRejectsInvalidTypeAndMissingTitles(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls', [
            'type' => 'quiz',
            'is_multiple' => false,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $this->assertValidationErrors($response, ['type', 'title_vi', 'title_en', 'title_ja']);
    }

    public function testCreateRejectsEmptyOptions(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'options' => [],
            ])
        );

        $this->assertValidationErrors($response, ['options']);
    }

    public function testCreateRejectsExpiresAtNotAfterToday(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'expires_at' => now()->toDateString(),
            ])
        );

        $this->assertValidationErrors($response, ['expires_at']);
    }

    public function testCreateRejectsMaxChoiceGreaterThanOptionCount(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'is_multiple' => true,
                'max_choice' => 5,
                'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ])
        );

        $this->assertValidationErrors($response, ['max_choice']);
    }

    public function testCreateRejectsNonIntegerVoteOptions(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->votePayload([
                'options' => ['not-an-id'],
            ])
        );

        $this->assertValidationErrors($response, ['options.0']);
    }

    public function testCreateRejectsVoteOptionForMissingUser(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->votePayload([
                'options' => [999999],
            ])
        );

        $this->assertValidationErrors($response, ['options.0']);
    }

    public function testCreateRejectsDuplicateVoteCandidates(): void
    {
        $candidate = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->votePayload([
                'options' => [$candidate->id, $candidate->id],
            ])
        );

        $this->assertValidationErrors($response, ['options']);
    }

    public function testCreateRejectsNonObjectFeedbackOption(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'options' => ['plain string'],
            ])
        );

        $this->assertValidationErrors($response, ['options.0']);
    }

    public function testCreateRejectsFeedbackOptionWithMissingLabel(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'options' => [['label_vi' => 'A', 'label_en' => 'A']],
            ])
        );

        $this->assertValidationErrors($response, ['options.0.label_ja']);
    }

    public function testCreateRejectsFeedbackOptionWithTooLongLabel(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'options' => [['label_vi' => 'A', 'label_en' => str_repeat('a', 256), 'label_ja' => 'A']],
            ])
        );

        $this->assertValidationErrors($response, ['options.0.label_en']);
    }

    public function testCreateRejectsFeedbackOptionWithNonStringId(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'options' => [['id' => 123, 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ])
        );

        $this->assertValidationErrors($response, ['options.0.id']);
    }

    public function testCreateRejectsDuplicateFeedbackOptionIds(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'options' => [
                    ['id' => 'same', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                    ['id' => 'same', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                ],
            ])
        );

        $this->assertValidationErrors($response, ['options']);
    }

    public function testUpdateSyncsTargetUsersForSpecificUsersPoll(): void
    {
        $oldTarget = User::factory()->create();
        $newTarget = User::factory()->create();

        $poll = Poll::factory()
            ->specificUsers()
            ->create(['created_by' => $this->admin->id]);
        $poll->targetUsers()->sync([$oldTarget->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => 'specific_users',
            'target_users' => [$newTarget->id],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('poll_targets', ['poll_id' => $poll->id, 'user_id' => $newTarget->id]);
        $this->assertDatabaseMissing('poll_targets', ['poll_id' => $poll->id, 'user_id' => $oldTarget->id]);
    }

    public function testUpdateClearsTargetUsersWhenSwitchingAwayFromSpecificUsers(): void
    {
        $target = User::factory()->create();

        $poll = Poll::factory()
            ->specificUsers()
            ->create(['created_by' => $this->admin->id]);
        $poll->targetUsers()->sync([$target->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'target_type' => 'global',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.scope', 'global');

        $this->assertDatabaseMissing('poll_targets', ['poll_id' => $poll->id]);
    }

    public function testUpdateRejectsMaxChoiceGreaterThanOptionCount(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'is_multiple' => true,
            'max_choice' => 5,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $this->assertValidationErrors($response, ['max_choice']);
    }

    public function testUpdateRejectsInvalidFeedbackOptions(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'is_multiple' => false,
            'options' => [['label_vi' => 'A', 'label_en' => 'A']],
        ]);

        $this->assertValidationErrors($response, ['options.0.label_ja']);
    }

    public function testUpdateRejectsInvalidVoteOptions(): void
    {
        $poll = Poll::factory()
            ->vote()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'is_multiple' => false,
            'options' => ['not-an-id'],
        ]);

        $this->assertValidationErrors($response, ['options.0']);
    }

    public function testUpdateReportsEveryLockedFieldAfterResponses(): void
    {
        $workplace = Workplace::factory()->create();
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);
        PollResponse::factory()->forPoll($poll)->create();

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'workplace_id' => $workplace->id,
            'is_multiple' => true,
            'max_choice' => 2,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $this->assertValidationErrors($response, ['workplace_id', 'options', 'is_multiple', 'max_choice']);
    }

    public function testUpdateCanReplaceOptionsAndResetCountersWhileDraft(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->withFeedbackOptions([['id' => 'old', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']])
            ->create(['created_by' => $this->admin->id, 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'is_multiple' => true,
            'options' => [
                ['id' => 'new1', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['id' => 'new2', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.max_choice', 2);

        $poll->refresh();
        $this->assertEquals(['new1' => 0, 'new2' => 0], $poll->result_counters);
    }

    public function testActivateReturnsNotFoundForMissingPoll(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls/999999/activate');

        $response->assertStatus(404);
    }

    public function testDeactivateReturnsNotFoundForMissingPoll(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/manage/polls/999999/deactivate');

        $response->assertStatus(404);
    }

    public function testResponsesReturnsNotFoundForMissingPoll(): void
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/manage/polls/999999/responses');

        $response->assertStatus(404);
    }

    public function testCreateAcceptsMultipleChoiceWithoutExplicitMaxChoice(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson(
            '/manage/polls',
            $this->feedbackPayload([
                'is_multiple' => true,
                'options' => [
                    ['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                    ['label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
                ],
            ])
        );

        $response->assertStatus(200)->assertJsonPath('data.max_choice', 2);
    }

    public function testActivateRejectsAlreadyActivePoll(): void
    {
        $poll = Poll::factory()
            ->active()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/activate");

        // PollController returns the error code only; ActionResult data is not forwarded.
        $response->assertStatus(422)->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertTrue($poll->fresh()->is_active);
    }

    public function testDeactivateRejectsAlreadyInactivePoll(): void
    {
        $poll = Poll::factory()->create(['created_by' => $this->admin->id, 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'api')->postJson("/manage/polls/{$poll->id}/deactivate");

        // PollController returns the error code only; ActionResult data is not forwarded.
        $response->assertStatus(422)->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertFalse($poll->fresh()->is_active);
    }

    /**
     * An active poll has no responses yet, so UpdatePollRequest lets it through and the
     * action is what rejects the locked field.
     */
    public function testUpdateRejectsLockedFieldOnActivePollWithoutResponses(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->active()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'is_multiple' => true,
            'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        // PollController returns the error code only, so the locked-field message never
        // reaches the client; the poll staying untouched is the observable effect.
        $response->assertStatus(422)->assertJsonPath('error', ErrorCode::UnprocessableEntity->value);
        $this->assertEquals([], $poll->fresh()->options);
    }

    public function testUpdateRejectsVoteOptionForMissingUser(): void
    {
        $poll = Poll::factory()
            ->vote()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'options' => [999999],
        ]);

        $this->assertValidationErrors($response, ['options.0']);
    }

    public function testUpdateRejectsDuplicateVoteCandidates(): void
    {
        $poll = Poll::factory()
            ->vote()
            ->create(['created_by' => $this->admin->id]);
        $candidate = User::factory()->create();

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'options' => [$candidate->id, $candidate->id],
        ]);

        $this->assertValidationErrors($response, ['options']);
    }

    public function testUpdateRejectsNonObjectFeedbackOption(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'options' => ['plain string'],
        ]);

        $this->assertValidationErrors($response, ['options.0']);
    }

    public function testUpdateRejectsFeedbackOptionWithTooLongLabel(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'options' => [['label_vi' => 'A', 'label_en' => str_repeat('a', 256), 'label_ja' => 'A']],
        ]);

        $this->assertValidationErrors($response, ['options.0.label_en']);
    }

    public function testUpdateRejectsFeedbackOptionWithNonStringId(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'options' => [['id' => 123, 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
        ]);

        $this->assertValidationErrors($response, ['options.0.id']);
    }

    public function testUpdateRejectsDuplicateFeedbackOptionIds(): void
    {
        $poll = Poll::factory()
            ->feedback()
            ->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin, 'api')->putJson("/manage/polls/{$poll->id}", [
            'title_vi' => 'T',
            'title_en' => 'T',
            'title_ja' => 'T',
            'options' => [
                ['id' => 'same', 'label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A'],
                ['id' => 'same', 'label_vi' => 'B', 'label_en' => 'B', 'label_ja' => 'B'],
            ],
        ]);

        $this->assertValidationErrors($response, ['options']);
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

    private function feedbackPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'type' => 'feedback',
                'target_type' => 'global',
                'title_vi' => 'Khao sat',
                'title_en' => 'Survey',
                'title_ja' => '調査',
                'is_active' => false,
                'is_required' => false,
                'is_multiple' => false,
                'options' => [['label_vi' => 'A', 'label_en' => 'A', 'label_ja' => 'A']],
            ],
            $overrides
        );
    }

    private function votePayload(array $overrides = []): array
    {
        return array_merge(
            [
                'type' => 'vote',
                'target_type' => 'global',
                'title_vi' => 'Bau cu',
                'title_en' => 'Election',
                'title_ja' => '選挙',
                'is_active' => false,
                'is_required' => false,
                'is_multiple' => false,
                'options' => [],
            ],
            $overrides
        );
    }
}
