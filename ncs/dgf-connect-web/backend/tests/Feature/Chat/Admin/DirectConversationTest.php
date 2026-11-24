<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemConversationKey;
use App\Enums\PermissionName;
use App\Models\ChatConversation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class DirectConversationTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testReturnsAConversationIdentifierWhenADirectConversationExists(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $employee = User::factory()->create();
        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => now(),
        ]);
        $this->addParticipant($conversation, $employee, [
            'role' => ChatParticipantRole::Employee->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/direct-conversation?user_id={$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.conversation_id', $conversation->id);
    }

    public function testReturnsNullWhenNoDirectConversationExists(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);
        $employee = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/direct-conversation?user_id={$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.conversation_id', null);
    }

    public function testRejectsTheRequestWithoutChatViewPermission(): void
    {
        $admin = User::factory()->create();
        $employee = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/direct-conversation?user_id={$employee->id}");

        $response->assertStatus(403);
    }

    public function testRejectsAMissingUserId(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/direct-conversation');

        $response->assertStatus(422);
    }

    public function testRejectsAnUnknownUserId(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatView]);

        $response = $this->actingAs($admin, 'api')->getJson('/admin/chat/direct-conversation?user_id=999999');

        $response->assertStatus(422);
    }

    public function testTheInternalAdminGroupIsNeverOfferedAsADirectConversation(): void
    {
        [$actor, $other] = $this->twoAdminsOfTheInternalGroup();

        $response = $this->actingAs($actor, 'api')->getJson("/admin/chat/direct-conversation?user_id={$other->id}");

        $response->assertOk();
        $response->assertJsonPath('data.conversation_id', null);
    }

    public function testARealDirectChatStillWinsOverTheInternalAdminGroup(): void
    {
        [$actor, $other] = $this->twoAdminsOfTheInternalGroup();

        $conversation = $this->makeConversation(['type' => ChatConversationType::AdminGroup->value]);
        foreach ([$actor, $other] as $member) {
            $this->addParticipant($conversation, $member, [
                'role' => ChatParticipantRole::Admin->value,
                'status' => ChatParticipantStatus::Active->value,
                'joined_at' => now(),
            ]);
        }

        $response = $this->actingAs($actor, 'api')->getJson("/admin/chat/direct-conversation?user_id={$other->id}");

        $response->assertOk();
        $response->assertJsonPath('data.conversation_id', $conversation->id);
    }

    /** @return array{0: User, 1: User} */
    private function twoAdminsOfTheInternalGroup(): array
    {
        $role = Role::query()->firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager']);
        foreach ([PermissionName::ManagerAuth, PermissionName::ChatView] as $permission) {
            $role->givePermission(
                Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )
            );
        }

        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->addRole($role);
        $second->addRole($role);

        $internal = ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->firstOrFail();
        $this->assertSame(2, $internal->participants()->where('status', ChatParticipantStatus::Active->value)->count());

        return [$first->fresh(), $second->fresh()];
    }
}
