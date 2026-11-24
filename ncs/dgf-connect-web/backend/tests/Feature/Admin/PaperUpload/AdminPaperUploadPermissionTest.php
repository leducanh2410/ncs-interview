<?php

namespace Tests\Feature\Admin\PaperUpload;

use App\Enums\PermissionName;
use App\Enums\UserPaperType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

class AdminPaperUploadPermissionTest extends TestCase
{
    use BuildsAdminPaperUploadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
        $this->fakeAdminScanOcr();
    }

    public function testCaptureEndpointsRejectAnAdminWithoutTheUploadPermission(): void
    {
        $employee = $this->employee();

        foreach ([$this->adminWithPermissions(), $this->adminWithPermissions(PermissionName::UserPaperList)] as $a) {
            $this->actingAs($a, 'api')
                ->postJson("/admin/users/{$employee->id}/paper-uploads", [
                    'paper_type' => 'foreigner_card_scan',
                    'file' => $this->scanImage(),
                ])
                ->assertStatus(403);
        }

        $this->assertDatabaseCount('admin_paper_uploads', 0);
    }

    public function testUploadingAgainstAnAccountThatIsNotAnEmployeeIsRefusedBeforeAnythingIsSpent(): void
    {
        $admin = $this->uploadingAdmin();
        // Two ways to be the wrong target: another admin, and a bare account with no role
        // at all. Neither has a reconciliation row, which is the only way back to a
        // submitted scan.
        $targets = [$this->adminWithPermissions(PermissionName::UserPaperUpload), User::factory()->create()];

        foreach ($targets as $target) {
            $this->actingAs($admin, 'api')
                ->postJson("/admin/users/{$target->id}/paper-uploads", [
                    'paper_type' => UserPaperType::ForeignerCardScan->value,
                    'file' => $this->scanImage(),
                ])
                ->assertStatus(404);
        }

        // No row, no stored image, and no queued read: the OCR budget is paid per read.
        $this->assertDatabaseCount('admin_paper_uploads', 0);
        $this->assertEmpty(Storage::allFiles());
        Queue::assertNothingPushed();
    }

    public function testTheUploadPermissionAloneDoesNotOpenTheReadSurfaces(): void
    {
        $admin = $this->adminWithPermissions(PermissionName::UserPaperUpload);
        $employee = $this->employee();

        $this->actingAs($admin, 'api')->getJson('/admin/paper-reconciliation')->assertStatus(403);
        $this->actingAs($admin, 'api')
            ->getJson("/admin/paper-reconciliation/{$employee->id}")
            ->assertStatus(403);
    }

    public function testAnotherAdminsUploadIsNotReadableSubmittableOrDiscardableThroughTheCaptureEndpoints(): void
    {
        $owner = $this->uploadingAdmin();
        $other = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($owner, $employee);

        $this->actingAs($other, 'api')
            ->getJson("/admin/paper-uploads/{$row->id}")
            ->assertStatus(403);
        $this->actingAs($other, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", ['fields' => ['name' => 'X']])
            ->assertStatus(403);
        $this->actingAs($other, 'api')
            ->deleteJson("/admin/paper-uploads/{$row->id}")
            ->assertStatus(403);
    }

    public function testASubmittedUploadStaysOwnerOnlyOnTheCaptureEndpoints(): void
    {
        $owner = $this->uploadingAdmin();
        $other = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($owner, $employee);
        $this->actingAs($owner, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();

        // Readable to everyone through the reconciliation surface, not through here.
        $this->actingAs($other, 'api')
            ->getJson("/admin/paper-uploads/{$row->id}")
            ->assertStatus(403);
    }
}
