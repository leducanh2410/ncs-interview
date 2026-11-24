<?php

namespace Tests\Unit\Jobs;

use App\Actions\Chat\Lifecycle\DeleteClosedSupportChatsAction;
use App\Enums\ChatConversationStatus;
use App\Enums\ChatConversationType;
use App\Jobs\DeleteClosedSupportChats;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteClosedSupportChatsTest extends TestCase
{
    use RefreshDatabase;

    public function testSkipsWhenRetentionIsZero(): void
    {
        config(['chat.support.retention_days' => 0]);

        $conversation = ChatConversation::factory()
            ->support()
            ->closed()
            ->create([
                'closed_at' => now()->subYear(),
            ]);

        $this->runJob();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function testDeletesExpiredClosedSupportConversation(): void
    {
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->support()
            ->closed()
            ->create([
                'closed_at' => now()->subDays(181),
            ]);

        $this->runJob();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
    }

    public function testKeepsConversationWithinRetentionPeriod(): void
    {
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->support()
            ->closed()
            ->create([
                'closed_at' => now()->subDays(90),
            ]);

        $this->runJob();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function testIgnoresOpenSupportConversations(): void
    {
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->support()
            ->create([
                'status' => ChatConversationStatus::Open->value,
                'closed_at' => null,
            ]);

        $this->runJob();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function testIgnoresNonSupportConversations(): void
    {
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->closed()
            ->create([
                'type' => ChatConversationType::AdminGroup->value,
                'closed_at' => now()->subYear(),
            ]);

        $this->runJob();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function testDeletesGuestSupportConversation(): void
    {
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->closed()
            ->create([
                'type' => ChatConversationType::GuestSupport->value,
                'closed_at' => now()->subDays(181),
            ]);

        $this->runJob();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
    }

    public function testDeletesAttachmentFilesFromStorage(): void
    {
        Storage::fake('local');
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->support()
            ->closed()
            ->create([
                'closed_at' => now()->subDays(181),
            ]);
        $attachment = $this->makeAttachment($conversation, 'chat/test-file.jpg');
        Storage::disk('local')->put('chat/test-file.jpg', 'content');

        $this->runJob();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing('chat/test-file.jpg');
    }

    public function testKeepsAttachmentsOfNonExpiredConversations(): void
    {
        Storage::fake('local');
        config(['chat.support.retention_days' => 180]);

        $conversation = ChatConversation::factory()
            ->support()
            ->closed()
            ->create([
                'closed_at' => now()->subDays(90),
            ]);
        $attachment = $this->makeAttachment($conversation, 'chat/keep-file.jpg');
        Storage::disk('local')->put('chat/keep-file.jpg', 'content');

        $this->runJob();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('chat_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists('chat/keep-file.jpg');
    }

    public function testRetainsConversationWhenStorageDeleteReturnsFalse(): void
    {
        config(['chat.support.retention_days' => 180]);

        $conv = ChatConversation::factory()
            ->support()
            ->closed()
            ->create(['closed_at' => now()->subDays(181)]);
        $attach = $this->makeAttachment($conv, 'chat/stuck-file.jpg', 'local');

        // Simulate Storage::delete returning false (e.g. permission denied) without throwing.
        $badDisk = \Mockery::mock();
        $badDisk->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($badDisk);

        $this->runJob();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conv->id]);
        $this->assertDatabaseHas('chat_attachments', ['id' => $attach->id]);
    }

    public function testPartialAttachmentFailureKeepsAllRecordsForNextRunRetry(): void
    {
        Storage::fake('local');
        config(['chat.support.retention_days' => 180]);

        $conv = ChatConversation::factory()
            ->support()
            ->closed()
            ->create(['closed_at' => now()->subDays(181)]);

        // file-ok.jpg will be deleted successfully from storage.
        $attachOk = $this->makeAttachment($conv, 'chat/file-ok.jpg', 'local');
        Storage::disk('local')->put('chat/file-ok.jpg', 'ok');

        // file-fail.jpg cannot be deleted (simulated by unconfigured disk).
        $attachFail = $this->makeAttachment($conv, 'chat/file-fail.jpg', 'unconfigured-disk');

        $this->runJob();

        // Conversation must be kept because one attachment failed.
        $this->assertDatabaseHas('chat_conversations', ['id' => $conv->id]);

        // Both attachment records must be kept. On the next run Storage::delete
        // returns true for the already-gone file (idempotent), so the conversation
        // can be retried cleanly without degradation.
        $this->assertDatabaseHas('chat_attachments', ['id' => $attachOk->id]);
        $this->assertDatabaseHas('chat_attachments', ['id' => $attachFail->id]);
    }

    public function testRetainsConversationWhenAttachmentDeleteFailsButCleansOthers(): void
    {
        Storage::fake('local');
        config(['chat.support.retention_days' => 180]);

        $expiredAt = now()->subDays(181);

        // Conv A: attachment on a properly faked disk — delete will succeed.
        $convA = ChatConversation::factory()
            ->support()
            ->closed()
            ->create(['closed_at' => $expiredAt]);
        $attachA = $this->makeAttachment($convA, 'chat/file-a.jpg', 'local');
        Storage::disk('local')->put('chat/file-a.jpg', 'content');

        // Conv B: attachment on an unconfigured disk — Storage::disk() throws, simulating
        // a storage failure. The conversation and its DB row must survive so the next
        // cleanup run can retry via the still-present conversation record.
        $convB = ChatConversation::factory()
            ->support()
            ->closed()
            ->create(['closed_at' => $expiredAt]);
        $attachB = $this->makeAttachment($convB, 'chat/file-b.jpg', 'unconfigured-disk');

        $this->runJob();

        // Conv A: fully cleaned up.
        $this->assertDatabaseMissing('chat_conversations', ['id' => $convA->id]);
        $this->assertDatabaseMissing('chat_attachments', ['id' => $attachA->id]);
        Storage::disk('local')->assertMissing('chat/file-a.jpg');

        // Conv B: retained in DB so the next run can retry.
        $this->assertDatabaseHas('chat_conversations', ['id' => $convB->id]);
        $this->assertDatabaseHas('chat_attachments', ['id' => $attachB->id]);
    }

    private function runJob(): void
    {
        (new DeleteClosedSupportChats())->handle(app(DeleteClosedSupportChatsAction::class));
    }

    private function makeAttachment(
        ChatConversation $conversation,
        string $path = 'chat/file.jpg',
        string $disk = 'local'
    ): ChatAttachment {
        $message = ChatMessage::factory()->create(['conversation_id' => $conversation->id]);
        $attachment = ChatAttachment::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
        ChatMessageAttachment::create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);

        return $attachment;
    }
}
