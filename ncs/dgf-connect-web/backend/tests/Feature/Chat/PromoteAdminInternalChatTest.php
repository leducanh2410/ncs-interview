<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemConversationKey;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class PromoteAdminInternalChatTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    public function testPromotesAnExistingGroupAndKeepsItsMessages(): void
    {
        $admin = $this->adminOfTheInternalGroup();
        $seeded = $this->internalGroup();
        $handMade = $this->handMadeGroupWithHistory($admin);

        $this->artisan('chat:promote-admin-internal', ['conversation' => $handMade->id])->assertExitCode(0);

        $this->assertSame(ChatSystemConversationKey::AdminInternal->value, $handMade->fresh()->system_key);
        $this->assertDatabaseMissing('chat_conversations', ['id' => $seeded->id]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $handMade->id,
            'body' => 'Morning everyone',
        ]);
        $this->assertSame(
            ChatParticipantStatus::Active->value,
            ChatParticipant::query()
                ->where('conversation_id', $handMade->id)
                ->where('user_id', $admin->id)
                ->value('status')
        );
    }

    public function testPromotionStillWorksAfterTheSyncHasWrittenItsOwnSystemMessages(): void
    {
        $admin = $this->adminOfTheInternalGroup();
        $handMade = $this->handMadeGroupWithHistory($admin);

        $seeded = $this->internalGroup();
        $this->assertTrue(
            $seeded->messages()->exists(),
            'the seeded group is expected to carry the sync bookkeeping this test is about'
        );

        $this->artisan('chat:promote-admin-internal', ['conversation' => $handMade->id])->assertExitCode(0);

        $this->assertSame(ChatSystemConversationKey::AdminInternal->value, $handMade->fresh()->system_key);
        $this->assertDatabaseMissing('chat_conversations', ['id' => $seeded->id]);
    }

    public function testPromotionIsRefusedWhenTheInternalGroupHoldsMessagesPeopleWrote(): void
    {
        $admin = $this->adminOfTheInternalGroup();
        $seeded = $this->internalGroup();
        $handMade = $this->handMadeGroupWithHistory($admin);

        $this->addMessage($seeded, $admin, ['body' => 'Written in the wrong group']);

        $this->artisan('chat:promote-admin-internal', ['conversation' => $handMade->id])->assertExitCode(1);

        $this->assertNull($handMade->fresh()->system_key);
        $this->assertDatabaseHas('chat_conversations', ['id' => $seeded->id]);
    }

    public function testOnlyAnAdminGroupCanBePromoted(): void
    {
        $this->adminOfTheInternalGroup();
        $support = $this->makeConversation(['type' => ChatConversationType::Support->value]);

        $this->artisan('chat:promote-admin-internal', ['conversation' => $support->id])->assertExitCode(1);

        $this->assertNull($support->fresh()->system_key);
    }

    public function testPromotingAGroupBuiltThroughTheRealFlowNormalisesEveryMemberToAdmin(): void
    {
        $creator = $this->adminOfTheInternalGroup([PermissionName::ChatCreate, PermissionName::ChatEdit]);
        $second = $this->adminOfTheInternalGroup();
        $third = $this->adminOfTheInternalGroup();

        $created = $this->actingAs($creator, 'api')->postJson('/admin/chat/conversations', [
            'type' => ChatConversationType::AdminGroup->value,
            'title' => 'Ban điều hành',
            'participant_user_ids' => [$second->id, $third->id],
        ]);
        $created->assertStatus(200);
        $conversationId = $created->json('data.id');

        $this->assertSame(
            ChatParticipantRole::Employee->value,
            ChatParticipant::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $second->id)
                ->value('role'),
            'the create flow is expected to add invited members as employees'
        );

        $this->artisan('chat:promote-admin-internal', ['conversation' => $conversationId])->assertExitCode(0);

        foreach ([$creator, $second, $third] as $member) {
            $this->assertSame(
                ChatParticipantRole::Admin->value,
                ChatParticipant::query()
                    ->where('conversation_id', $conversationId)
                    ->where('user_id', $member->id)
                    ->value('role')
            );
        }
    }

    public function testNormalisingTheRoleKeepsTheReadMarkerAndJoinDate(): void
    {
        $admin = $this->adminOfTheInternalGroup();
        $conversation = $this->handMadeGroupWithHistory($admin);

        $participant = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $admin->id)
            ->firstOrFail();
        $joinedAt = now()->subMonth();
        $participant
            ->forceFill([
                'role' => ChatParticipantRole::Employee->value,
                'last_read_message_id' => 4242,
                'joined_at' => $joinedAt,
            ])
            ->save();

        $this->artisan('chat:promote-admin-internal', ['conversation' => $conversation->id])->assertExitCode(0);

        $participant->refresh();
        $this->assertSame(ChatParticipantRole::Admin->value, $participant->role);
        $this->assertSame(4242, (int) $participant->last_read_message_id);
        $this->assertSame($joinedAt->toDateTimeString(), $participant->joined_at->toDateTimeString());
    }

    private function internalGroup(): ChatConversation
    {
        return ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->firstOrFail();
    }

    private function handMadeGroupWithHistory(User $admin): ChatConversation
    {
        $conversation = $this->makeConversation([
            'type' => ChatConversationType::AdminGroup->value,
            'status' => ChatConversationStatus::Open->value,
            'title' => 'Ban điều hành',
        ]);
        $this->addParticipant($conversation, $admin);
        $this->addMessage($conversation, $admin, ['body' => 'Morning everyone']);

        return $conversation;
    }

    /** @param array<int, PermissionName> $extraPermissions */
    private function adminOfTheInternalGroup(array $extraPermissions = []): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager']);
        foreach ([PermissionName::ManagerAuth, PermissionName::ChatView, ...$extraPermissions] as $permission) {
            if ($role->hasPermission($permission->value)) {
                continue;
            }

            $role->givePermission(
                Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )
            );
        }

        $user = User::factory()->create();
        $user->addRole($role);

        return $user->fresh();
    }
}
