<?php

namespace Tests\Feature\Admin\AdminLeave;

use App\Enums\AdminLeaveType;
use App\Enums\PermissionName;
use App\Models\AdminLeaveRequest;
use App\Models\AdminLeaveRequestAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class AdminLeaveAttachmentTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private User $manager;

    private User $requester;

    private User $otherAdmin;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->requester = User::factory()->create();
        $this->otherAdmin = User::factory()->create();
        $this->giveGlobalPermission($this->manager, PermissionName::AdminLeaveManage);
        $this->giveGlobalPermission($this->requester, PermissionName::ManagerAuth);
        $this->giveGlobalPermission($this->otherAdmin, PermissionName::ManagerAuth);

        $this->disk = config('filesystems.default');
        Storage::fake($this->disk);

        Carbon::setTestNow(Carbon::parse('2027-09-01 09:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testThreeFilesAreAccepted(): void
    {
        $response = $this->submit([
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'),
        ]);

        $response->assertStatus(201);
        $this->assertSame(3, AdminLeaveRequestAttachment::query()->count());
    }

    public function testAFourthFileIsRejectedAndNothingIsCreated(): void
    {
        $response = $this->submit([
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.png'),
            UploadedFile::fake()->image('c.png'),
            UploadedFile::fake()->image('d.png'),
        ]);

        $response->assertStatus(422);
        $this->assertNoRequestOrFileWasCreated();
    }

    public function testAnOversizedFileIsRejected(): void
    {
        // 6 MB, over the 5 MB cap.
        $response = $this->submit([UploadedFile::fake()->create('big.pdf', 6144, 'application/pdf')]);

        $response->assertStatus(422);
        $this->assertNoRequestOrFileWasCreated();
    }

    public function testADisallowedFileTypeIsRejected(): void
    {
        $response = $this->submit([UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')]);

        $response->assertStatus(422);
        $this->assertNoRequestOrFileWasCreated();
    }

    public function testAFileRenamedToAnAllowedExtensionIsStillRejectedOnItsRealType(): void
    {
        // Extension says pdf, contents say something else — the mimetypes rule is what catches it.
        $response = $this->submit([UploadedFile::fake()->create('payload.pdf', 10, 'application/x-msdownload')]);

        $response->assertStatus(422);
        $this->assertNoRequestOrFileWasCreated();
    }

    public function testValidPdfContentRenamedToADisallowedExtensionIsStillRejected(): void
    {
        // Content is a genuine PDF, so `mimes`/`mimetypes` alone would pass it; only `extensions`
        // catches the disallowed client-supplied name.
        $response = $this->submit([UploadedFile::fake()->create('receipt.exe', 10, 'application/pdf')]);

        $response->assertStatus(422);
        $this->assertNoRequestOrFileWasCreated();
    }

    public function testARequestWithNoFilesIsAccepted(): void
    {
        $this->submit([])->assertStatus(201);

        $this->assertSame(0, AdminLeaveRequestAttachment::query()->count());
    }

    public function testCreateResponseReturnsTimeLimitedUrlsNotRawStorageFields(): void
    {
        $response = $this->submit([
            UploadedFile::fake()->image('certificate.png'),
            UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ])->assertStatus(201);

        $attachments = $response->json('data.attachments');
        $this->assertCount(2, $attachments);
        foreach ($attachments as $attachment) {
            $this->assertNotEmpty($attachment['url']);
            $this->assertArrayHasKey('original_name', $attachment);
            $this->assertArrayHasKey('is_image', $attachment);
            // The stored disk/path are internal storage details, not part of the client contract.
            $this->assertArrayNotHasKey('disk', $attachment);
            $this->assertArrayNotHasKey('path', $attachment);
        }
    }

    public function testDetailReturnsATimeLimitedUrlPerAttachment(): void
    {
        $id = $this->submit([
            UploadedFile::fake()->image('certificate.png'),
            UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ])->json('data.id');

        $detail = $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests/' . $id)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $detail['attachments']);
        foreach ($detail['attachments'] as $attachment) {
            $this->assertNotEmpty($attachment['url']);
            $this->assertArrayHasKey('is_image', $attachment);
            $this->assertArrayHasKey('original_name', $attachment);
            $this->assertArrayHasKey('size_bytes', $attachment);
        }
        $this->assertTrue($detail['attachments'][0]['is_image']);
        $this->assertFalse($detail['attachments'][1]['is_image']);
    }

    public function testTheRequesterCanSeeTheirOwnAttachmentUrls(): void
    {
        $id = $this->submit([UploadedFile::fake()->image('certificate.png')])->json('data.id');

        $this->actingAs($this->requester, 'api')
            ->getJson('/admin/admin-leave-requests/' . $id)
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments');
    }

    public function testAUserWhoIsNeitherRequesterNorLeaveManagerIsRefusedTheDetail(): void
    {
        $id = $this->submit([UploadedFile::fake()->image('certificate.png')])->json('data.id');

        $this->actingAs($this->otherAdmin, 'api')
            ->getJson('/admin/admin-leave-requests/' . $id)
            ->assertForbidden();
    }

    public function testARequestWithNoAttachmentsReturnsAnEmptyAttachmentList(): void
    {
        $id = $this->submit([])->json('data.id');

        $this->actingAs($this->manager, 'api')
            ->getJson('/admin/admin-leave-requests/' . $id)
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments');
    }

    /**
     * @param UploadedFile[] $files
     */
    private function submit(array $files): TestResponse
    {
        return $this->actingAs($this->requester, 'api')->postJson('/admin/admin-leave-requests', [
            'type' => AdminLeaveType::PaidLeave->value,
            'start_date' => '2027-09-06',
            'end_date' => '2027-09-07',
            'reason' => 'Medical',
            'attachments' => $files,
        ]);
    }

    private function assertNoRequestOrFileWasCreated(): void
    {
        // Validation runs before the action, so nothing should have reached the database or disk.
        $this->assertSame(0, AdminLeaveRequest::query()->count());
        $this->assertSame(0, AdminLeaveRequestAttachment::query()->count());
        $this->assertSame([], Storage::disk($this->disk)->allFiles('admin-leave'));
    }
}
