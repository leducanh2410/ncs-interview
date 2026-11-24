<?php

namespace Tests\Feature\Admin\PaperUpload;

use App\Enums\AdminPaperUploadStatus;
use App\Enums\ErrorCode;
use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Jobs\ExtractAdminPaperUploadOcrJob;
use App\Models\AdminPaperUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

/**
 * Every cell of the design's state x event matrix, including the ones nobody designs for:
 * a queued read finishing after the admin already submitted, discarded, or retook.
 */
class AdminPaperUploadStateMachineTest extends TestCase
{
    use BuildsAdminPaperUploadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
    }

    public function testUnreadableCardFailsWithoutSpendingAQueueRetry(): void
    {
        $this->fakeAdminScanOcr(unreadable: true);
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);

        $row->refresh();
        $this->assertSame(AdminPaperUploadStatus::OcrFailed->value, $row->status);
        $this->assertSame(OcrRequiredFieldsMissingException::ERROR_CODE, $row->ocr_error);
        // The partial read is kept: the admin can see what the machine did manage.
        $this->assertIsArray($row->ocr_fields);
    }

    public function testProviderFailureIsRecordedDistinctlyFromAnUnreadableCard(): void
    {
        $this->fakeAdminScanOcr(fails: true);
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$employee->id}/paper-uploads", [
            'paper_type' => 'foreigner_card_scan',
            'file' => $this->scanImage(),
        ]);
        $uploadId = $response->json('data.id');

        // A provider error is thrown out of handle() so the queue retries; failed() records it.
        $this->assertThrows(fn() => $this->runOcrJob($uploadId), RuntimeException::class);
        (new ExtractAdminPaperUploadOcrJob($uploadId))->failed(new RuntimeException('OCR provider request failed'));

        $row = AdminPaperUpload::query()->find($uploadId);
        $this->assertSame(AdminPaperUploadStatus::OcrFailed->value, $row->status);
        $this->assertNotSame(
            OcrRequiredFieldsMissingException::ERROR_CODE,
            $row->ocr_error,
            'the client must be able to say "try again" rather than "retake the photo"'
        );
    }

    public function testSubmitIsRejectedWhileTheReadIsStillRunning(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$employee->id}/paper-uploads", [
            'paper_type' => 'foreigner_card_scan',
            'file' => $this->scanImage(),
        ]);
        $uploadId = $response->json('data.id');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$uploadId}/submit", ['fields' => ['name' => 'NGUYEN VAN A']])
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::AdminPaperUploadOcrPending->value);
    }

    public function testBudgetIsRefusedAtUploadRatherThanAfterAFourthPaidRead(): void
    {
        $this->fakeAdminScanOcr(unreadable: true);
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$response, $row] = $this->uploadAndRead($admin, $employee);
            $response->assertOk();
            $this->assertSame($attempt, $row->refresh()->ocr_run_count);
        }

        $filesBefore = Storage::allFiles();

        $fourth = $this->actingAs($admin, 'api')->postJson("/admin/users/{$employee->id}/paper-uploads", [
            'paper_type' => 'foreigner_card_scan',
            'file' => $this->scanImage(),
        ]);

        $fourth->assertStatus(422)->assertJsonPath('error', ErrorCode::AdminPaperUploadOcrUnreadable->value);
        // Asserting the status code alone would pass an implementation that stored the
        // image and spent the read before refusing. The point of the guard is the money.
        $this->assertSame($filesBefore, Storage::allFiles(), 'no file was stored');
        $this->assertSame(3, $row->refresh()->ocr_run_count, 'no fourth read was spent');
    }

    public function testManualConfirmationIsRejectedUntilTheBudgetIsSpentAndAcceptedAfter(): void
    {
        $this->fakeAdminScanOcr(unreadable: true);
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);

        $rejected = $this->actingAs($admin, 'api')->postJson("/admin/paper-uploads/{$row->id}/submit", [
            'fields' => ['name' => 'NGUYEN VAN A'],
        ]);
        $rejected->assertStatus(422)->assertJsonPath('error', ErrorCode::AdminPaperUploadOcrUnreadable->value);
        $this->assertSame(1, $rejected->json('data.ocr_run_count'));
        $this->assertSame(3, $rejected->json('data.max_ocr_runs'));

        $this->uploadAndRead($admin, $employee);
        [, $row] = $this->uploadAndRead($admin, $employee);

        $accepted = $this->actingAs($admin, 'api')->postJson("/admin/paper-uploads/{$row->id}/submit", [
            'fields' => [
                'name' => 'NGUYEN VAN A',
                'residence_number' => 'AB12345678CD',
                'residence_expiry_date' => '2030-01-01',
                'residence_status' => 'study_abroad',
                'residence_status_raw' => '留学',
            ],
        ]);

        $accepted->assertOk();
        $this->assertTrue($accepted->json('data.confirmed_without_ocr'));
        $this->assertSame(
            '留学',
            $row->refresh()->confirmed_fields['residence_status_raw'],
            'the manual path is the only one where the admin supplies the printed wording'
        );
    }

    public function testAReadFinishingAfterSubmitLeavesTheRowAlone(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();

        $submittedAt = $row->refresh()->submitted_at;
        $confirmed = $row->confirmed_fields;

        // The same job runs again, as a retried queue message would.
        $this->runOcrJob($row->id);

        $row->refresh();
        $this->assertSame(AdminPaperUploadStatus::Submitted->value, $row->status);
        $this->assertEquals($submittedAt, $row->submitted_at);
        $this->assertEquals($confirmed, $row->confirmed_fields);
    }

    public function testAReadFinishingAfterDiscardChangesNothing(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$employee->id}/paper-uploads", [
            'paper_type' => 'foreigner_card_scan',
            'file' => $this->scanImage(),
        ]);
        $uploadId = $response->json('data.id');

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/paper-uploads/{$uploadId}")
            ->assertStatus(204);

        $this->runOcrJob($uploadId);

        $this->assertDatabaseMissing('admin_paper_uploads', ['id' => $uploadId]);
    }

    public function testAReadFinishingAfterARetakeDoesNotResurrectTheReplacedDraft(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $first = $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$employee->id}/paper-uploads", [
                'paper_type' => 'foreigner_card_scan',
                'file' => $this->scanImage(),
            ])
            ->json('data.id');

        // Retake before the first read ran.
        [, $second] = $this->uploadAndRead($admin, $employee);

        $this->runOcrJob($first);

        $this->assertDatabaseMissing('admin_paper_uploads', ['id' => $first]);
        $this->assertSame(AdminPaperUploadStatus::OcrReady->value, $second->refresh()->status);
    }

    public function testASecondSubmitIsRejectedSoASubmittedUploadIsImmutable(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);
        $payload = $this->confirmPayload($row->refresh());

        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $payload)
            ->assertOk();
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $payload)
            ->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::AdminPaperUploadAlreadySubmitted->value);
    }

    public function testASubmittedUploadCannotBeDiscarded(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->deleteJson("/admin/paper-uploads/{$row->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('admin_paper_uploads', ['id' => $row->id]);
    }

    public function testUnknownConfirmKeysAreRejected(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);

        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", [
                'fields' => ['name' => 'NGUYEN VAN A', 'paper_status' => 'approved'],
            ])
            ->assertStatus(422);
    }

    public function testResidenceStatusRawIsCarriedFromTheCardNotFromTheClient(): void
    {
        $this->fakeAdminScanOcr();
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $row] = $this->uploadAndRead($admin, $employee);
        $payload = $this->confirmPayload($row->refresh());
        $payload['fields']['residence_status_raw'] = 'something the admin typed';

        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $payload)
            ->assertOk();

        $this->assertSame('留学', $row->refresh()->confirmed_fields['residence_status_raw']);
    }
}
