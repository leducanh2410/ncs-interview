<?php

namespace Tests\Unit\Actions\Admin\Group;

use App\Actions\Admin\Group\RecomputeUserMembershipsAction;
use App\Enums\ChatConversationType;
use App\Enums\Gender;
use App\Enums\GroupKind;
use App\Enums\JapaneseLevel;
use App\Enums\Nationality;
use App\Enums\SourceTier;
use App\Models\ChatConversation;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class RecomputeUserMembershipsActionTest extends TestCase
{
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private RecomputeUserMembershipsAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RecomputeUserMembershipsAction::class);
    }

    public function testRecomputesWorkplaceCurrentMembershipsForAttachedWorkplaces(): void
    {
        $user = User::factory()->create();
        $workplaceA = Workplace::factory()->create();
        $workplaceB = Workplace::factory()->create();

        $user->workplaces()->attach([$workplaceA->id, $workplaceB->id]);
        $this->clearUserMemberships($user);

        ($this->action)($user, [GroupKind::WorkplaceCurrent]);

        $actualWorkplaceIds = UserGroup::query()
            ->join('groups', 'groups.id', '=', 'user_groups.group_id')
            ->where('user_groups.user_id', $user->id)
            ->where('groups.kind', GroupKind::WorkplaceCurrent->value)
            ->pluck('groups.source_workplace_id')
            ->all();

        $this->assertEqualsCanonicalizing([$workplaceA->id, $workplaceB->id], $actualWorkplaceIds);
    }

    public function testRecomputesWorktimeMembershipUsingLatestShiftAsTieBreaker(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $position = $this->createWorkplacePosition($workplace);

        $this->createCompletedShift($user, $workplace, $position, '2026-05-01', '09:00:00', '17:00:00');
        $this->createCompletedShift($user, $workplace, $position, '2026-05-02', '18:00:00', '22:00:00');
        $this->clearUserMemberships($user);

        ($this->action)($user, [GroupKind::Worktime]);

        $group = Group::query()
            ->join('user_groups', 'user_groups.group_id', '=', 'groups.id')
            ->where('user_groups.user_id', $user->id)
            ->where('groups.kind', GroupKind::Worktime->value)
            ->select('groups.*')
            ->firstOrFail();

        $this->assertSame(SourceTier::Night, $group->source_tier);
    }

    public function testRecomputesWorkplaceSeniorityMembershipAcrossTierBoundary(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::factory()->create();
        $position = $this->createWorkplacePosition($workplace);
        $user->workplaces()->attach($workplace->id);

        for ($day = 1; $day <= 30; $day++) {
            $this->createCompletedShift($user, $workplace, $position, sprintf('2026-05-%02d', $day));
        }
        $this->clearUserMemberships($user);

        ($this->action)($user, [GroupKind::WorkplaceSeniority], $workplace->id);

        $group = Group::query()
            ->join('user_groups', 'user_groups.group_id', '=', 'groups.id')
            ->where('user_groups.user_id', $user->id)
            ->where('groups.kind', GroupKind::WorkplaceSeniority->value)
            ->where('groups.source_workplace_id', $workplace->id)
            ->select('groups.*')
            ->firstOrFail();

        $this->assertSame(SourceTier::Veteran, $group->source_tier);
    }

    public function testRecomputesRuleBasedMembershipsForMatchingGroups(): void
    {
        $user = User::factory()->create();
        $this->updateUserInfo($user, [
            'prefecture' => 'tokyo',
            'japanese_level' => JapaneseLevel::N3->value,
            'gender' => Gender::Female->value,
            'nationality' => Nationality::Vietnam->value,
        ]);

        $matchingGroup = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo N3',
            predicate: [
                ['field' => 'prefecture', 'values' => ['tokyo']],
                ['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]],
            ]
        );
        $this->createGroup(
            GroupKind::RuleBased,
            'Osaka N3',
            predicate: [
                ['field' => 'prefecture', 'values' => ['osaka']],
                ['field' => 'japanese_level', 'values' => [JapaneseLevel::N3->value]],
            ]
        );
        $this->clearUserMemberships($user);

        ($this->action)($user, [GroupKind::RuleBased]);

        $groupIds = UserGroup::query()->where('user_id', $user->id)->pluck('group_id')->all();

        $this->assertSame([$matchingGroup->id], $groupIds);
    }

    public function testLockedUserShortCircuitLeavesExistingMembershipsUntouched(): void
    {
        $user = User::factory()->create([
            'is_disabled' => true,
        ]);
        $this->updateUserInfo($user, [
            'prefecture' => 'tokyo',
            'japanese_level' => JapaneseLevel::N3->value,
        ]);

        $existingGroup = $this->createGroup(
            GroupKind::RuleBased,
            'Existing Group',
            predicate: [['field' => 'prefecture', 'values' => ['osaka']]]
        );
        $this->createGroup(
            GroupKind::RuleBased,
            'Should Not Be Added',
            predicate: [['field' => 'prefecture', 'values' => ['tokyo']]]
        );
        UserGroup::query()->create([
            'group_id' => $existingGroup->id,
            'user_id' => $user->id,
        ]);

        ($this->action)($user, [GroupKind::RuleBased]);

        $groupIds = UserGroup::query()->where('user_id', $user->id)->pluck('group_id')->all();

        $this->assertSame([$existingGroup->id], $groupIds);
    }

    public function testSyncChatDeferredReportsAffectedGroupIdsWithoutSyncing(): void
    {
        $user = User::factory()->create();
        $this->updateUserInfo($user, ['prefecture' => 'tokyo']);
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo',
            predicate: [['field' => 'prefecture', 'values' => ['tokyo']]]
        );
        $this->clearUserMemberships($user);

        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);

        $affected = ($this->action)($user, [GroupKind::RuleBased], syncChat: false);

        // Membership is still written to user_groups...
        $this->assertSame([$group->id], $affected);
        $this->assertDatabaseHas('user_groups', ['group_id' => $group->id, 'user_id' => $user->id]);
        // ...but the chat sync is deferred, so no participant is added yet.
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
    }

    public function testDefaultInvocationSyncsAffectedGroupChat(): void
    {
        $user = User::factory()->create();
        $this->updateUserInfo($user, ['prefecture' => 'tokyo']);
        $group = $this->createGroup(
            GroupKind::RuleBased,
            'Tokyo',
            predicate: [['field' => 'prefecture', 'values' => ['tokyo']]]
        );
        $this->clearUserMemberships($user);

        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
            'user_group_id' => $group->id,
        ]);

        ($this->action)($user, [GroupKind::RuleBased]);

        // Default path syncs inline, so the participant is now active.
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
    }
}
