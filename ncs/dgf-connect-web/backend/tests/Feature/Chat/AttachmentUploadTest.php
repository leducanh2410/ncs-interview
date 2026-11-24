<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatMessageType;
use App\Events\NewChatMessage;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsChatFixtures;
use Tests\TestCase;

class AttachmentUploadTest extends TestCase
{
    use BuildsChatFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('chat.attachments.disk'));
    }

    private function disk(): string
    {
        return config('chat.attachments.disk');
    }

    public function testUploadsImageAndFileAttachments(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->post("/user/chat/conversations/{$conversation->id}/messages", [
            'body' => 'see attached',
            'files' => [
                UploadedFile::fake()->image('photo.jpg', 200, 200),
                UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertOk();
        // A non-image attachment present => message_type is file.
        $response->assertJsonPath('data.message_type', ChatMessageType::File->value);
        $response->assertJsonCount(2, 'data.attachments');

        $attachments = collect($response->json('data.attachments'))->keyBy('type');
        $this->assertSame('image', $attachments['image']['type']);
        $this->assertSame('file', $attachments['file']['type']);
        $this->assertStringContainsString('/chat/attachments/', $attachments['image']['url']);
        $this->assertSame('photo.jpg', $attachments['image']['filename']);
        $this->assertSame('report.pdf', $attachments['file']['filename']);
        // Image fakes carry real bytes; size reflects the stored (original) file.
        $this->assertGreaterThan(0, $attachments['image']['size_bytes']);

        // Image has an auth-checked thumbnail_url; non-image does not.
        $this->assertNotNull($attachments['image']['thumbnail_url']);
        $this->assertStringContainsString('/chat/attachments/', $attachments['image']['thumbnail_url']);
        $this->assertStringContainsString('/thumbnail', $attachments['image']['thumbnail_url']);
        $this->assertNull($attachments['file']['thumbnail_url']);

        $this->assertSame(
            2,
            ChatMessage::query()->where('conversation_id', $conversation->id)->first()->attachments->count()
        );
        $this->assertDatabaseCount('chat_attachments', 2);
        $this->assertDatabaseCount('chat_message_attachments', 2);

        foreach ($response->json('data.attachments') as $a) {
            $record = \App\Models\ChatAttachment::find($a['attachment_id']);
            Storage::disk($this->disk())->assertExists($record->path);
            if ($a['type'] === 'image') {
                $this->assertNotNull($record->thumbnail_path);
                Storage::disk($this->disk())->assertExists($record->thumbnail_path);
            } else {
                $this->assertNull($record->thumbnail_path);
            }
        }
    }

    public function testDerivesImageTypeWhenAllFilesAreImages(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->post("/user/chat/conversations/{$conversation->id}/messages", [
            'files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.message_type', ChatMessageType::Image->value);
    }

    public function testAllowsAttachmentOnlyMessageWithoutBody(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->post("/user/chat/conversations/{$conversation->id}/messages", [
            'files' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.body', null);
    }

    public function testEmployeeBlockedFromSendingImageWhenPermissionDisabled(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['employee_can_send_image' => false]);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->post("/user/chat/conversations/{$conversation->id}/messages", [
            'files' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('chat_attachments', 0);
    }

    public function testEmployeeBlockedFromSendingFileWhenPermissionDisabled(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['employee_can_send_file' => false]);
        $this->addParticipant($conversation, $user);

        $response = $this->actingAs($user, 'api')->post("/user/chat/conversations/{$conversation->id}/messages", [
            'files' => [UploadedFile::fake()->create('report.pdf', 50, 'application/pdf')],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('chat_attachments', 0);
    }

    public function testEmployeeCanSendImageWhenPermissionEnabled(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation(['employee_can_send_image' => true]);
        $this->addParticipant($conversation, $user);

        $this->actingAs($user, 'api')
            ->post("/user/chat/conversations/{$conversation->id}/messages", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertOk();
    }

    public function testThumbnailStoredForImageButNotForFile(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $this->actingAs($user, 'api')
            ->post("/user/chat/conversations/{$conversation->id}/messages", [
                'files' => [
                    UploadedFile::fake()->image('photo.png', 400, 300),
                    UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
                ],
            ])
            ->assertOk();

        $attachments = \App\Models\ChatAttachment::orderBy('id')->get();
        $this->assertCount(2, $attachments);

        $image = $attachments->first(fn($a) => str_ends_with($a->original_name, '.png'));
        $file = $attachments->first(fn($a) => str_ends_with($a->original_name, '.pdf'));

        $this->assertNotNull($image->thumbnail_path);
        $this->assertStringStartsWith('chat/thumbs/', $image->thumbnail_path);
        $this->assertSame($this->disk(), $image->thumbnail_disk);
        Storage::disk($this->disk())->assertExists($image->thumbnail_path);

        $this->assertNull($file->thumbnail_path);
    }

    public function testBroadcastPayloadIncludesAttachments(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);

        $this->actingAs($user, 'api')
            ->post("/user/chat/conversations/{$conversation->id}/messages", [
                'files' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertOk();

        $message = ChatMessage::query()->where('conversation_id', $conversation->id)->latest('id')->first();
        $payload = (new NewChatMessage($message))->broadcastWith();

        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('image', $payload['attachments'][0]['type']);
        $this->assertStringContainsString('/chat/attachments/', $payload['attachments'][0]['url']);
        $this->assertArrayHasKey('thumbnail_url', $payload['attachments'][0]);
        $this->assertNotNull($payload['attachments'][0]['thumbnail_url']);
    }

    public function testIdempotentRetryDoesNotDuplicateFiles(): void
    {
        $user = User::factory()->create();
        $conversation = $this->makeConversation();
        $this->addParticipant($conversation, $user);
        $clientId = (string) Str::uuid();

        $payload = fn() => [
            'body' => 'attached',
            'client_message_id' => $clientId,
            'files' => [UploadedFile::fake()->image('a.jpg')],
        ];

        $first = $this->actingAs($user, 'api')->post(
            "/user/chat/conversations/{$conversation->id}/messages",
            $payload()
        );
        $second = $this->actingAs($user, 'api')->post(
            "/user/chat/conversations/{$conversation->id}/messages",
            $payload()
        );

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('chat_attachments', 1);
        $this->assertDatabaseCount('chat_message_attachments', 1);
    }
}
