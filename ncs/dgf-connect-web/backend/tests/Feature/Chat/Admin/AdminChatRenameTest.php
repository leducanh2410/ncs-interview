<?php

namespace Tests\Feature\Chat\Admin;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Models\ChatAuditLog;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AdminChatRenameTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testRenameRequiresChatEditPermission(): void
    {
        $user = User::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
        ]);

        $response = $this->actingAs($user, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'New Name',
        ]);

        $response->assertStatus(403);
    }

    public function testRenameRequiresTitle(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);

        $response = $this->actingAs($admin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/title",
            []
        );

        $response->assertStatus(422)->assertJsonPath('data.title.0', 'The title field is required.');
    }

    public function testRenameFailsForSupportConversationType(): void
    {
        $actor = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::Support->value,
        ]);
        $this->addParticipant($conversation, $actor, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($actor, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'New Name',
        ]);

        $response->assertStatus(422);
    }

    public function testRenameShiftGroupUpdatesTitle(): void
    {
        Event::fake();
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::ShiftGroup->value,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'Renamed Shift Group',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Renamed Shift Group');
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'title' => 'Renamed Shift Group',
        ]);
    }

    public function testRenameUserGroupUpdatesTitle(): void
    {
        Event::fake();
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::UserGroup->value,
        ]);
        $this->addParticipant($conversation, $admin, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'Renamed User Group',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Renamed User Group');
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'title' => 'Renamed User Group',
        ]);
    }

    public function testRenameFailsWhenNotAdminParticipant(): void
    {
        $owner = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($owner);

        $otherAdmin = $this->createUserWithPermissions([PermissionName::ChatEdit]);

        $response = $this->actingAs($otherAdmin, 'api')->patchJson(
            "/admin/chat/conversations/{$conversation->id}/title",
            ['title' => 'New Name']
        );

        $response->assertStatus(403);
    }

    public function testRenameFailsWhenConversationIsClosed(): void
    {
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);
        $conversation->forceFill(['status' => ChatConversationStatus::Closed->value])->save();

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'Should Not Work',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('chat_conversations', [
            'id' => $conversation->id,
            'title' => 'Should Not Work',
        ]);
    }

    public function testRenameUpdatesTitle(): void
    {
        Event::fake();
        $admin = $this->createUserWithPermissions([PermissionName::ChatEdit]);
        $conversation = $this->createManualGroup($admin);

        $response = $this->actingAs($admin, 'api')->patchJson("/admin/chat/conversations/{$conversation->id}/title", [
            'title' => 'New Group Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'New Group Name');
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'title' => 'New Group Name',
        ]);
        $this->assertSame(
            1,
            ChatAuditLog::query()
                ->where('conversation_id', $conversation->id)
                ->where('action', ChatAuditAction::ConversationRenamed->value)
                ->count()
        );
    }

    private function createManualGroup(User $creator): ChatConversation
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'created_by_user_id' => $creator->id,
        ]);
        $this->addParticipant($conversation, $creator, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
        ]);

        return $conversation;
    }
}
