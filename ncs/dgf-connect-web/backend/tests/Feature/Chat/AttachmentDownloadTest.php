<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatParticipantRole;
use App\Enums\ChatParticipantStatus;
use App\Enums\PermissionName;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsChatFixtures;
use Tests\Concerns\BuildsGroupFixtures;
use Tests\TestCase;

class AttachmentDownloadTest extends TestCase
{
    use BuildsChatFixtures;
    use BuildsGroupFixtures;
    use RefreshDatabase;

    private function disk(): string
    {
        return config('chat.attachments.disk');
    }

    private function makeAttachment(ChatConversation $conversation, User $sender): ChatAttachment
    {
        $message = $this->addMessage($conversation, $sender, ['body' => 'see file']);
        $path = "chat/{$conversation->id}/file.pdf";
        Storage::disk($this->disk())->put($path, 'pdf-bytes');

        $attachment = ChatAttachment::query()->create([
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'created_by_user_id' => $sender->id,
        ]);

        ChatMessageAttachment::query()->create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);

        return $attachment;
    }

    public function testParticipantCanDownloadAttachment(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $owner);
        $attachment = $this->makeAttachment($conversation, $owner);

        $response = $this->actingAs($owner, 'api')->get("/chat/attachments/{$attachment->id}");

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=file.pdf');
    }

    public function testNonParticipantCannotDownloadAttachment(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $owner);
        $attachment = $this->makeAttachment($conversation, $owner);

        $this->actingAs($stranger, 'api')
            ->get("/chat/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    public function testRemovedParticipantCannotDownloadAttachment(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $removed = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $owner);
        $this->addParticipant($conversation, $removed, ['role' => ChatParticipantRole::Employee->value]);
        $attachment = $this->makeAttachment($conversation, $owner);

        // Remove the second participant.
        $conversation
            ->participants()
            ->where('user_id', $removed->id)
            ->update([
                'status' => ChatParticipantStatus::Removed->value,
                'removed_at' => now(),
            ]);

        $this->actingAs($removed, 'api')
            ->get("/chat/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    public function testSupportViewAdminCanDownloadAttachment(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $conversation = ChatConversation::factory()
            ->support()
            ->create(['created_by_user_id' => $owner->id]);
        $this->addParticipant($conversation, $owner, ['role' => ChatParticipantRole::Employee->value]);
        $attachment = $this->makeAttachment($conversation, $owner);

        $admin = $this->createUserWithPermissions([PermissionName::ChatView, PermissionName::ChatAssign]);

        $this->actingAs($admin, 'api')
            ->get("/chat/attachments/{$attachment->id}")
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Thumbnail route — same auth model as download
    // -------------------------------------------------------------------------

    private function makeAttachmentWithThumbnail(ChatConversation $conversation, User $sender): ChatAttachment
    {
        $message = $this->addMessage($conversation, $sender, ['body' => 'see image']);
        $path = "chat/{$conversation->id}/photo.png";
        $thumbPath = 'chat/thumbs/01THUMB.jpg';
        Storage::disk($this->disk())->put($path, 'img-bytes');
        Storage::disk($this->disk())->put($thumbPath, 'thumb-bytes');

        $attachment = ChatAttachment::query()->create([
            'disk' => $this->disk(),
            'path' => $path,
            'thumbnail_path' => $thumbPath,
            'thumbnail_disk' => $this->disk(),
            'original_name' => 'photo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 9,
            'created_by_user_id' => $sender->id,
        ]);

        ChatMessageAttachment::query()->create([
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'position' => 0,
        ]);

        return $attachment;
    }

    public function testParticipantCanDownloadThumbnail(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $owner);
        $attachment = $this->makeAttachmentWithThumbnail($conversation, $owner);

        $response = $this->actingAs($owner, 'api')
            ->get("/chat/attachments/{$attachment->id}/thumbnail")
            ->assertOk();

        $response->assertHeader('content-disposition', 'attachment; filename=photo.jpg');
    }

    public function testNonParticipantCannotDownloadThumbnail(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $owner);
        $attachment = $this->makeAttachmentWithThumbnail($conversation, $owner);

        $this->actingAs($stranger, 'api')
            ->get("/chat/attachments/{$attachment->id}/thumbnail")
            ->assertStatus(404);
    }

    public function testThumbnailReturns404WhenNoneExists(): void
    {
        Storage::fake($this->disk());
        $owner = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $owner);
        $attachment = $this->makeAttachment($conversation, $owner); // no thumbnail_path

        $this->actingAs($owner, 'api')
            ->get("/chat/attachments/{$attachment->id}/thumbnail")
            ->assertStatus(404);
    }
}
