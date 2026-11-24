<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatParticipantRole;
use App\Enums\SupportType;
use App\Events\ChatConversationCreated;
use App\Events\NewChatMessage;
use App\Jobs\AutoAssignSupportAdminJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportCreateTest extends TestCase
{
    use RefreshDatabase;

    public function testEmployeeCreatesSupportChatWithInitialMessage(): void
    {
        Event::fake([ChatConversationCreated::class, NewChatMessage::class]);
        $employee = User::factory()->create();

        $response = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'title' => 'Cannot check in',
            'body' => 'The check-in button is greyed out.',
        ]);

        $response->assertOk();
        $conversationId = $response->json('data.id');

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'type' => ChatConversationType::Support->value,
            'created_by_user_id' => $employee->id,
            'title' => 'Cannot check in',
        ]);
        $this->assertDatabaseHas('chat_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $employee->id,
            'role' => ChatParticipantRole::Employee->value,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversationId,
            'sender_user_id' => $employee->id,
            'message_type' => ChatMessageType::Text->value,
            'body' => 'The check-in button is greyed out.',
        ]);

        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversationId,
            'action' => ChatAuditAction::ConversationCreated->value,
        ]);
        Event::assertDispatched(ChatConversationCreated::class);
        Event::assertDispatched(NewChatMessage::class);
    }

    public function testSupportChatAppearsInCreatorConversationList(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'api')
            ->postJson('/user/chat/support-conversations', ['body' => 'Help please'])
            ->assertOk();

        $list = $this->actingAs($employee, 'api')->getJson('/user/chat/conversations');
        $list->assertOk();
        $this->assertCount(1, $list->json('data.data'));
        $this->assertSame(ChatConversationType::Support->value, $list->json('data.data.0.type'));
    }

    public function testEmployeeMayOpenMultipleSupportChats(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'api')
            ->postJson('/user/chat/support-conversations', ['body' => 'First issue'])
            ->assertOk();
        $this->actingAs($employee, 'api')
            ->postJson('/user/chat/support-conversations', ['body' => 'Second issue'])
            ->assertOk();

        $this->assertSame(
            2,
            ChatConversation::query()
                ->where('type', ChatConversationType::Support->value)
                ->where('created_by_user_id', $employee->id)
                ->count()
        );
    }

    public function testResendingSameClientMessageIdIsIdempotent(): void
    {
        $employee = User::factory()->create();
        $clientMessageId = (string) Str::uuid();

        $first = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'body' => 'Idempotent issue',
            'client_message_id' => $clientMessageId,
        ]);
        $first->assertOk();
        $conversationId = $first->json('data.id');

        // A retry into the same conversation's message endpoint must not duplicate.
        $retry = $this->actingAs($employee, 'api')->postJson("/user/chat/conversations/{$conversationId}/messages", [
            'body' => 'Idempotent issue',
            'client_message_id' => $clientMessageId,
        ]);
        $retry->assertOk();

        $this->assertSame(
            1,
            ChatMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('client_message_id', $clientMessageId)
                ->count()
        );
    }

    public function testCreateSupportConversationWithSupportType(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        $employee = User::factory()->create();

        $response = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'body' => 'I need help with my salary.',
            'support_type' => SupportType::Salary->value,
        ]);

        $response->assertOk();
        $conversationId = $response->json('data.id');

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversationId,
            'support_type' => SupportType::Salary->value,
        ]);
        Bus::assertDispatched(AutoAssignSupportAdminJob::class, fn($job) => $job->conversationId === $conversationId);
    }

    public function testCreateSupportConversationIgnoresShiftId(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        $employee = User::factory()->create();

        $response = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'body' => 'Issue with my shift.',
            'support_type' => SupportType::Shift->value,
            'shift_id' => 99999,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $response->json('data.id'),
            'shift_id' => null,
        ]);
        $this->assertArrayNotHasKey('shift_id', $response->json('data'));
    }

    public function testCreateSupportConversationWithoutSupportType(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        $employee = User::factory()->create();

        $response = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'body' => 'General help.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('chat_conversations', [
            'id' => $response->json('data.id'),
            'support_type' => null,
        ]);
        Bus::assertDispatched(AutoAssignSupportAdminJob::class);
    }

    public function testCreateSupportConversationReturns422ForInvalidType(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'api')
            ->postJson('/user/chat/support-conversations', [
                'body' => 'Help.',
                'support_type' => 'invalid_type',
            ])
            ->assertStatus(422);
    }

    public function testResponseIncludesSupportTypeWithoutShiftId(): void
    {
        Bus::fake([AutoAssignSupportAdminJob::class]);
        $employee = User::factory()->create();

        $response = $this->actingAs($employee, 'api')->postJson('/user/chat/support-conversations', [
            'body' => 'Shift issue.',
            'support_type' => SupportType::Shift->value,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.support_type', SupportType::Shift->value);
        $this->assertArrayNotHasKey('shift_id', $response->json('data'));
    }
}
