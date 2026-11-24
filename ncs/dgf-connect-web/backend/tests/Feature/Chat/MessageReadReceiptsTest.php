<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Events\ChatConversationRead;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class MessageReadReceiptsTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    public function testSplitsParticipantsIntoSeenAndUnseen(): void
    {
        $sender = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $sender);

        $reader = User::factory()->create();
        $lagging = User::factory()->create();
        $this->joinAsAdmin($conversation, $reader);
        $this->joinAsAdmin($conversation, $lagging);

        $message = $this->addMessage($conversation, $sender);

        $this->addParticipantRead($conversation, $reader, $message->id);

        $response = $this->actingAs($sender, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/{$message->id}/receipts"
        );

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.seen_count'));
        $this->assertSame(2, $response->json('data.total_count'));
        $this->assertSame([$reader->id], collect($response->json('data.seen'))->pluck('user_id')->all());
        $this->assertSame([$lagging->id], collect($response->json('data.unseen'))->pluck('user_id')->all());
        $this->assertNotNull($response->json('data.seen.0.seen_at'));
    }

    public function testExcludesTheSenderAndTheCaller(): void
    {
        $sender = $this->chatAdmin();
        $caller = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $sender);
        $this->joinAsAdmin($conversation, $caller);

        $message = $this->addMessage($conversation, $sender);

        $response = $this->actingAs($caller, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/{$message->id}/receipts"
        );

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.total_count'));
        $this->assertSame([], $response->json('data.seen'));
        $this->assertSame([], $response->json('data.unseen'));
    }

    public function testExcludesParticipantsWhoJoinedAfterTheMessage(): void
    {
        $sender = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $sender, now()->subDay());

        $message = $this->addMessage($conversation, $sender, ['created_at' => now()->subHours(2)]);

        $latecomer = User::factory()->create();
        $this->joinAsAdmin($conversation, $latecomer, now());

        $response = $this->actingAs($sender, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/{$message->id}/receipts"
        );

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.total_count'));
    }

    public function testRemovedParticipantsAreNotCounted(): void
    {
        $sender = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $sender);

        $left = User::factory()->create();
        $this->addParticipant($conversation, $left, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Removed->value,
            'joined_at' => now()->subDay(),
            'removed_at' => now(),
        ]);

        $message = $this->addMessage($conversation, $sender);

        $response = $this->actingAs($sender, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/{$message->id}/receipts"
        );

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.total_count'));
    }

    public function testRejectsAMessageFromAnotherConversation(): void
    {
        $admin = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $admin);

        $other = $this->makeConversation();
        $foreignMessage = $this->addMessage($other, User::factory()->create());

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/{$foreignMessage->id}/receipts"
        );

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ChatMessageNotInConversation->value, $response->json('error'));
    }

    public function testANonNumericMessageIdIsNotFoundRatherThanAServerError(): void
    {
        $admin = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $admin);

        $response = $this->actingAs($admin, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/abc/receipts"
        );

        $response->assertStatus(404);
    }

    public function testHidesReceiptsFromNonParticipants(): void
    {
        $outsider = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $member = User::factory()->create();
        $this->joinAsAdmin($conversation, $member);
        $message = $this->addMessage($conversation, $member);

        $response = $this->actingAs($outsider, 'api')->getJson(
            "/admin/chat/conversations/{$conversation->id}/messages/{$message->id}/receipts"
        );

        $response->assertStatus(404);
    }

    public function testAdminConversationDetailExposesEveryParticipantReadMarker(): void
    {
        $admin = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $admin);

        $reader = User::factory()->create();
        $this->joinAsAdmin($conversation, $reader);
        $message = $this->addMessage($conversation, $admin);
        $this->addParticipantRead($conversation, $reader, $message->id);

        $response = $this->actingAs($admin, 'api')->getJson("/admin/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $participant = collect($response->json('data.participants'))->firstWhere('user_id', $reader->id);
        $this->assertSame($message->id, $participant['last_read_message_id']);
        $this->assertNotNull($participant['last_read_at']);
    }

    public function testEmployeeConversationDetailKeepsReadMarkersPrivate(): void
    {
        $employee = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $employee);

        $other = User::factory()->create();
        $this->addParticipant($conversation, $other);

        $response = $this->actingAs($employee, 'api')->getJson("/user/chat/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $participant = collect($response->json('data.participants'))->firstWhere('user_id', $other->id);
        $this->assertArrayNotHasKey('last_read_message_id', $participant);
    }

    public function testMarkingReadBroadcastsOnlyWhenTheMarkerMovesForward(): void
    {
        Event::fake([ChatConversationRead::class]);

        $admin = $this->chatAdmin();
        $conversation = $this->makeConversation();
        $this->joinAsAdmin($conversation, $admin);

        $sender = User::factory()->create();
        $this->joinAsAdmin($conversation, $sender);
        $message = $this->addMessage($conversation, $sender);
        $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => now()])->save();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/read")
            ->assertStatus(200);

        Event::assertDispatched(
            ChatConversationRead::class,
            fn(ChatConversationRead $event) => $event->conversationId === $conversation->id &&
                $event->userId === $admin->id &&
                $event->lastReadMessageId === $message->id
        );

        Event::assertDispatchedTimes(ChatConversationRead::class, 1);

        // Reading the same conversation again moves nothing, so no one needs to redraw receipts.
        $this->actingAs($admin, 'api')
            ->postJson("/admin/chat/conversations/{$conversation->id}/read")
            ->assertStatus(200);

        Event::assertDispatchedTimes(ChatConversationRead::class, 1);
    }

    private function chatAdmin(): User
    {
        return $this->createUserWithPermissions([PermissionName::ChatView]);
    }

    private function joinAsAdmin(
        ChatConversation $conversation,
        User $user,
        ?\Illuminate\Support\Carbon $joinedAt = null
    ): void {
        $this->addParticipant($conversation, $user, [
            'role' => ChatParticipantRole::Admin->value,
            'status' => ChatParticipantStatus::Active->value,
            'joined_at' => $joinedAt ?? now()->subDay(),
        ]);
    }

    private function addParticipantRead(ChatConversation $conversation, User $user, int $messageId): void
    {
        $conversation
            ->participants()
            ->where('user_id', $user->id)
            ->update(['last_read_message_id' => $messageId, 'last_read_at' => now()]);
    }
}
