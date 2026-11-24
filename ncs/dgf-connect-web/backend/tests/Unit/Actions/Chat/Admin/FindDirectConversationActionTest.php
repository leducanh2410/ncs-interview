<?php

namespace Tests\Unit\Actions\Chat\Admin;

use App\Actions\Chat\Admin\FindDirectConversationAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class FindDirectConversationActionTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testReturnsTheConversationWhenExactlyTwoActiveParticipantsMatch(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addActiveParticipant($conversation, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($conversation, $employee, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertSame($conversation->id, $result->getData()['conversation_id']);
    }

    public function testReturnsNullWhenTheTargetIsTheActorThemself(): void
    {
        // With actor === target the two whereHas clauses collapse into one condition, so
        // without the guard this would return the actor's most recent unrelated 1:1
        // conversation with someone else instead of "no match".
        $actor = User::factory()->create();
        $someoneElse = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addActiveParticipant($conversation, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($conversation, $someoneElse, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $actor->id);

        $this->assertNull($result->getData()['conversation_id']);
    }

    public function testReturnsNullWhenAThirdActiveParticipantExists(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addActiveParticipant($conversation, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($conversation, $employee, ChatParticipantRole::Employee);
        $this->addActiveParticipant($conversation, $other, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertNull($result->getData()['conversation_id']);
    }

    public function testReturnsNullWhenTheEmployeesParticipantStatusIsRemoved(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addActiveParticipant($conversation, $actor, ChatParticipantRole::Admin);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Removed->value,
            'joined_at' => now(),
            'removed_at' => now(),
        ]);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertNull($result->getData()['conversation_id']);
    }

    public function testReturnsNullWhenTheOnlyMatchingConversationIsClosed(): void
    {
        // SendMessageAction rejects closed conversations, so surfacing this id would send
        // the admin into a dead end instead of falling through to create-direct.
        $actor = User::factory()->create();
        $employee = User::factory()->create();
        $conversation = $this->makeConversation([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Closed->value,
        ]);
        $this->addActiveParticipant($conversation, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($conversation, $employee, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertNull($result->getData()['conversation_id']);
    }

    public function testReturnsTheOpenConversationEvenWhenAMoreRecentlyActiveClosedOneExists(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();

        $open = $this->makeConversation([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Open->value,
            'last_activity_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);
        $this->addActiveParticipant($open, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($open, $employee, ChatParticipantRole::Employee);

        $closed = $this->makeConversation([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Closed->value,
            'last_activity_at' => Carbon::parse('2026-02-01 10:00:00'),
        ]);
        $this->addActiveParticipant($closed, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($closed, $employee, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertSame($open->id, $result->getData()['conversation_id']);
    }

    public function testReturnsNullForAShiftGroupOrUserGroupConversationWithTheSameTwoMembers(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::ShiftGroup->value]);
        $this->addActiveParticipant($conversation, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($conversation, $employee, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertNull($result->getData()['conversation_id']);
    }

    public function testReturnsTheMostRecentlyActiveConversationWhenTwoCandidatesExist(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();

        $older = $this->makeConversation([
            'type' => ChatConversationType::AdminGroup->value,
            'last_activity_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);
        $this->addActiveParticipant($older, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($older, $employee, ChatParticipantRole::Employee);

        $newer = $this->makeConversation([
            'type' => ChatConversationType::AdminGroup->value,
            'last_activity_at' => Carbon::parse('2026-02-01 10:00:00'),
        ]);
        $this->addActiveParticipant($newer, $actor, ChatParticipantRole::Admin);
        $this->addActiveParticipant($newer, $employee, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertSame($newer->id, $result->getData()['conversation_id']);
    }

    public function testReturnsNullWhenTheActorIsNotAParticipant(): void
    {
        $actor = User::factory()->create();
        $employee = User::factory()->create();
        $someoneElse = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addActiveParticipant($conversation, $someoneElse, ChatParticipantRole::Admin);
        $this->addActiveParticipant($conversation, $employee, ChatParticipantRole::Employee);

        $action = new FindDirectConversationAction();
        $result = $action($actor, $employee->id);

        $this->assertNull($result->getData()['conversation_id']);
    }

    private function addActiveParticipant(ChatConversation $conversation, User $user, ChatParticipantRole $role): void
    {
        $this->addParticipant($conversation, $user, [
            'role' => $role->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => now(),
        ]);
    }
}
