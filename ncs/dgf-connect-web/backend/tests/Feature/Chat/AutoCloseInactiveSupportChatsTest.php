<?php

namespace Tests\Feature\Chat;

use App\Actions\Chat\Lifecycle\AutoCloseConversationAction;
use App\Actions\Chat\Message\RecordSystemMessageAction;
use App\Enums\ChatAuditAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Enums\ChatMessageType;
use App\Enums\ChatSystemMessageType;
use App\Events\ChatConversationStateChanged;
use App\Events\NewChatMessage;
use App\Jobs\AutoCloseInactiveSupportChats;
use App\Models\ChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class AutoCloseInactiveSupportChatsTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    private function runJob(): void
    {
        (new AutoCloseInactiveSupportChats())->handle(app(AutoCloseConversationAction::class));
    }

    public function testClosesInactiveSupportChat(): void
    {
        Event::fake([ChatConversationStateChanged::class, NewChatMessage::class]);
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['last_activity_at' => now()->subHours(30)]);

        $this->runJob();

        $conversation->refresh();
        $this->assertSame(ChatConversationStatus::Closed->value, $conversation->status);
        $this->assertNull($conversation->closed_by_user_id);
        $this->assertSame('auto_closed_inactivity', $conversation->close_reason);

        $this->assertDatabaseHas('chat_audit_logs', [
            'conversation_id' => $conversation->id,
            'action' => ChatAuditAction::AutoClosed->value,
            'actor_user_id' => null,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'message_type' => ChatMessageType::System->value,
            'body' => ChatSystemMessageType::ConversationClosed->value,
        ]);
        Event::assertDispatched(ChatConversationStateChanged::class);
        Event::assertDispatched(NewChatMessage::class);
    }

    public function testKeepsRecentlyActiveSupportChatOpen(): void
    {
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['last_activity_at' => now()->subHour()]);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }

    public function testSystemMessageDoesNotResetInactivityTimer(): void
    {
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['last_activity_at' => now()->subHours(30)]);

        // A system event (recorded now) must not bump last_activity_at, so the chat still closes.
        app(RecordSystemMessageAction::class)($conversation, ChatSystemMessageType::SupportAdminAssigned);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Closed->value, $conversation->fresh()->status);
    }

    public function testIgnoresNonSupportConversations(): void
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::AdminGroup->value,
            'last_activity_at' => now()->subHours(30),
        ]);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }

    public function testClosesInactiveGuestSupportChat(): void
    {
        Event::fake([ChatConversationStateChanged::class, NewChatMessage::class]);
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'last_activity_at' => now()->subHours(30),
        ]);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Closed->value, $conversation->fresh()->status);
    }

    public function testKeepsRecentlyActiveGuestSupportChatOpen(): void
    {
        $conversation = ChatConversation::factory()->create([
            'type' => ChatConversationType::GuestSupport->value,
            'last_activity_at' => now()->subHour(),
        ]);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }

    public function testRespectsConfiguredHours(): void
    {
        config(['chat.support.auto_close_inactive_hours' => 72]);
        // Inactive 30h: past the default 24h window but within the 72h override.
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['last_activity_at' => now()->subHours(30)]);

        $this->runJob();

        $this->assertSame(ChatConversationStatus::Open->value, $conversation->fresh()->status);
    }
}
