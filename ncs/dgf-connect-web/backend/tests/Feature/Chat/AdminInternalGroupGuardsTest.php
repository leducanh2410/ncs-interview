<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ChatSystemConversationKey;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The internal admin group belongs to the system: its roster follows manager.auth and nothing a
 * human does in the UI may take it apart. Every actor here reaches the group the real way — a
 * role carrying manager.auth makes them an admin participant — so these are the same accounts
 * the guards will meet in production.
 */
class AdminInternalGroupGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function testTheGroupCannotBeDeleted(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatDeleteConversation]);
        $conversation = $this->internalGroup();

        $response = $this->actingAs($admin, 'api')->deleteJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatSystemConversationImmutable->value, $response->json('error'));
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function testTheGroupCannotBeClosed(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit]);
        $conversation = $this->internalGroup();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/chat/conversations/{$conversation->id}/close", [
            'reason' => 'No longer needed',
        ]);

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatSystemConversationImmutable->value, $response->json('error'));
    }

    public function testParticipantsCannotBeAddedByHand(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit]);
        $conversation = $this->internalGroup();
        $outsider = User::factory()->create();

        $response = $this->actingAs($admin, 'api')->postJson(
            "/admin/chat/conversations/{$conversation->id}/participants",
            ['user_ids' => [$outsider->id]]
        );

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatSystemConversationImmutable->value, $response->json('error'));
        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function testParticipantsCannotBeRemovedByHand(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit]);
        $other = $this->adminMember([PermissionName::ChatView], 'manager-two');
        $conversation = $this->internalGroup();

        $response = $this->actingAs($admin, 'api')->deleteJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$other->id}"
        );

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatSystemConversationImmutable->value, $response->json('error'));
        $this->assertSame(ChatParticipantStatus::Active->value, $this->participantOf($other)->status);
    }

    public function testDeputyRoleCannotBeAssigned(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit]);
        $other = $this->adminMember([PermissionName::ChatView], 'manager-two');
        $conversation = $this->internalGroup();

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/participants/{$other->id}/role",
            ['role' => ChatParticipantRole::Deputy->value]
        );

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatSystemConversationImmutable->value, $response->json('error'));
        $this->assertSame(ChatParticipantRole::Admin->value, $this->participantOf($other)->role);
    }

    public function testEmployeePermissionsCannotBeChanged(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit]);
        $conversation = $this->internalGroup();

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/employee-permissions",
            ['employee_can_send_message' => false]
        );

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatSystemConversationImmutable->value, $response->json('error'));
    }

    public function testAnOrdinaryAdminCannotRenameTheGroup(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit]);
        $conversation = $this->internalGroup();
        $originalTitle = $conversation->title;

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'Renamed by a manager',
        ]);

        $response->assertStatus(403);
        $this->assertSame($originalTitle, $conversation->fresh()->title);
    }

    public function testAGlobalAdminCanRenameTheGroup(): void
    {
        $globalAdmin = $this->adminMember(
            [PermissionName::ChatView, PermissionName::ChatEdit],
            RoleName::GlobalAdmin->value
        );
        $conversation = $this->internalGroup();

        $response = $this->actingAs($globalAdmin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/title",
            ['title' => 'Ban điều hành']
        );

        $response->assertStatus(200);
        $this->assertSame('Ban điều hành', $conversation->fresh()->title);
    }

    public function testAnOrdinaryGroupIsStillFullyEditable(): void
    {
        $admin = $this->adminMember([PermissionName::ChatView, PermissionName::ChatEdit, PermissionName::ChatCreate]);

        $created = $this->actingAs($admin, 'api')->postJson('/admin/chat/conversations', [
            'type' => 'admin_group',
            'title' => 'Project chat',
            'participant_user_ids' => [User::factory()->create()->id],
        ]);
        $created->assertStatus(200);

        $conversationId = $created->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/admin/chat/conversations/{$conversationId}/title", ['title' => 'Renamed'])
            ->assertStatus(200);
    }

    public function testEveryHandEditEndpointTurnsANonMemberAwayWithoutRevealingTheGroupIsSystemOwned(): void
    {
        $outsider = $this->nonMemberAdmin();
        $conversation = $this->internalGroup();
        $member = $this->adminMember([PermissionName::ChatView]);
        $target = User::factory()->create();

        $calls = [
            'delete conversation' => fn() => $this->deleteJson("/admin/chat/conversations/{$conversation->id}"),
            'close conversation' => fn() => $this->postJson("/admin/chat/conversations/{$conversation->id}/close", [
                'reason' => 'No longer needed',
            ]),
            'add participants' => fn() => $this->postJson(
                "/admin/chat/conversations/{$conversation->id}/participants",
                ['user_ids' => [$target->id]]
            ),
            'remove participant' => fn() => $this->deleteJson(
                "/admin/chat/conversations/{$conversation->id}/participants/{$member->id}"
            ),
            'assign deputy role' => fn() => $this->patchJson(
                "/admin/chat/conversations/{$conversation->id}/participants/{$member->id}/role",
                ['role' => ChatParticipantRole::Deputy->value]
            ),
            'update employee permissions' => fn() => $this->patchJson(
                "/admin/chat/conversations/{$conversation->id}/employee-permissions",
                ['employee_can_send_message' => false]
            ),
        ];

        $this->actingAs($outsider, 'api');

        foreach ($calls as $label => $call) {
            $response = $call();

            $this->assertSame(403, $response->status(), "{$label} did not refuse a non-member");
            $this->assertSame(ErrorCode::Forbidden->value, $response->json('error'), "{$label} leaked the system key");
        }

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'system_key' => ChatSystemConversationKey::AdminInternal->value,
        ]);
    }

    private function nonMemberAdmin(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'auditor'], ['display_name' => 'Auditor']);

        $permissions = [
            PermissionName::ChatView,
            PermissionName::ChatEdit,
            PermissionName::ChatCreate,
            PermissionName::ChatDeleteConversation,
        ];

        foreach ($permissions as $permission) {
            $role->givePermission(
                Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )
            );
        }

        $user = User::factory()->create();
        $user->addRole($role);

        $this->assertDatabaseMissing('chat_participants', [
            'conversation_id' => $this->internalGroup()->id,
            'user_id' => $user->id,
        ]);

        return $user->fresh();
    }

    private function internalGroup(): ChatConversation
    {
        return ChatConversation::query()
            ->where('system_key', ChatSystemConversationKey::AdminInternal->value)
            ->firstOrFail();
    }

    private function participantOf(User $user): ChatParticipant
    {
        return ChatParticipant::query()
            ->where('conversation_id', $this->internalGroup()->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    /**
     * An account that reaches the group the way production does: a role carrying manager.auth,
     * which the membership sync turns into an active admin participant.
     *
     * @param array<int, PermissionName> $permissions
     */
    private function adminMember(array $permissions, string $roleName = 'manager'): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName], ['display_name' => $roleName]);

        foreach ([PermissionName::ManagerAuth, ...$permissions] as $permission) {
            $role->givePermission(
                Permission::query()->firstOrCreate(
                    ['name' => $permission->value],
                    ['display_name' => $permission->value]
                )
            );
        }

        $user = User::factory()->create();
        $user->addRole($role);

        return $user;
    }
}
