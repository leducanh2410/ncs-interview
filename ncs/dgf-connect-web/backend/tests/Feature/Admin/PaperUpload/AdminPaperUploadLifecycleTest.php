<?php

namespace Tests\Feature\Admin\PaperUpload;

use App\Enums\AdminPaperUploadStatus;
use App\Enums\UserPaperType;
use App\Models\AdminPaperUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

/**
 * Drives the whole capture flow through the real endpoints and the real job.
 *
 * This is the test that legitimises AdminPaperUploadFactory's ocrReady() and submitted()
 * states for the rest of the suite: it proves both are reachable without a fixture
 * inserting a status by hand.
 */
class AdminPaperUploadLifecycleTest extends TestCase
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

    public function testUploadReadConfirmSubmitThenRescan(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        // 1. Upload: the row exists, the read is queued, the file is on disk.
        [$upload, $row] = $this->uploadAndRead($admin, $employee);
        $upload->assertOk();
        $this->assertSame(1, $upload->json('data.ocr_run_count'));
        $this->assertNull($upload->json('data.recent_submission'), 'nobody has scanned this employee yet');
        Storage::assertExists($row->file_path);

        // 2. The queued read moved it on, and poll reports what it found.
        $this->assertSame(AdminPaperUploadStatus::OcrReady->value, $row->refresh()->status);

        $poll = $this->actingAs($admin, 'api')->getJson("/admin/paper-uploads/{$row->id}");
        $poll->assertOk();
        $this->assertSame(AdminPaperUploadStatus::OcrReady->value, $poll->json('data.status'));
        $this->assertSame('AB12345678CD', $poll->json('data.ocr_fields.residence_number'));
        $this->assertSame(3, $poll->json('data.max_ocr_runs'));

        // 3. Confirm with one correction, then submit.
        $submit = $this->actingAs($admin, 'api')->postJson(
            "/admin/paper-uploads/{$row->id}/submit",
            $this->confirmPayload($row->refresh(), ['name' => 'NGUYEN VAN B'])
        );
        $submit->assertOk();
        $this->assertSame(AdminPaperUploadStatus::Submitted->value, $submit->json('data.status'));
        $this->assertFalse($submit->json('data.confirmed_without_ocr'));

        $row->refresh();
        $this->assertSame($admin->id, $row->submitted_by_id);
        $this->assertNotNull($row->submitted_at);
        // Both readings are kept, so the record shows the admin overrode the machine.
        $this->assertSame('NGUYEN VAN A', $row->ocr_fields['name']);
        $this->assertSame('NGUYEN VAN B', $row->confirmed_fields['name']);
        // residence_status_raw is the server's copy of what the card said, not the client's.
        $this->assertSame('留学', $row->confirmed_fields['residence_status_raw']);

        // 4. A second capture for the same employee warns about the first, keeps both.
        [$second, $secondRow] = $this->uploadAndRead($admin, $employee);
        $second->assertOk();
        $this->assertSame(
            $admin->id,
            $second->json('data.recent_submission.submitted_by.id'),
            'the app must be able to say a colleague just scanned this employee'
        );
        $this->assertSame(1, $second->json('data.ocr_run_count'), 'a closed cycle starts the budget again');

        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$secondRow->id}/submit", $this->confirmPayload($secondRow->refresh()))
            ->assertOk();

        $history = AdminPaperUpload::query()
            ->where('user_id', $employee->id)
            ->whereNotNull('submitted_at')
            // submitted_at has second precision, so two submissions inside one test tie.
            // The id tiebreaker is what production orders by too (currentAdminScans and
            // the detail history both do), which is why the tie is harmless there.
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();
        $this->assertCount(2, $history, 'the previous submission stays in history untouched');
        $this->assertSame($secondRow->id, $history->first()->id, 'the newest submission is the current one');
    }

    public function testEmployeeDocumentsAreNeverTouched(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        // From the database, not the in-memory model: paper_status carries a DB default
        // the factory never sets, so an unrefreshed model reads null and the snapshot
        // would "detect" a change this feature did not make.
        $employee->refresh();
        $before = [
            'paper_status' => $employee->paper_status,
            'user_infos' => $employee->info?->refresh()?->toArray(),
        ];

        [, $row] = $this->uploadAndRead($admin, $employee);
        $this->actingAs($admin, 'api')
            ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
            ->assertOk();

        $employee->refresh();
        $this->assertSame($before['paper_status'], $employee->paper_status);
        $this->assertEquals($before['user_infos'], $employee->info?->refresh()?->toArray());
        $this->assertDatabaseCount('user_papers', 0);
        $this->assertDatabaseCount('user_paper_items', 0);
        $this->assertDatabaseCount('user_paper_steps', 0);
        $this->assertDatabaseCount('user_paper_ocr_results', 0);
    }

    public function testDraftIsReplacedByARetakeRatherThanAccumulating(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $first] = $this->uploadAndRead($admin, $employee);
        $firstPath = $first->file_path;

        [$second, $secondRow] = $this->uploadAndRead($admin, $employee);
        $second->assertOk();

        $this->assertSame(2, $secondRow->ocr_run_count, 'the budget carries across a retake');
        $this->assertDatabaseMissing('admin_paper_uploads', ['id' => $first->id]);
        Storage::assertMissing($firstPath);
        $this->assertSame(
            1,
            AdminPaperUpload::query()->where('user_id', $employee->id)->whereNull('submitted_at')->count()
        );
    }

    public function testTwoAdminsScanningTheSameEmployeeDoNotOverwriteEachOther(): void
    {
        $employee = $this->employee();
        $first = $this->uploadingAdmin();
        $second = $this->uploadingAdmin();

        [, $firstRow] = $this->uploadAndRead($first, $employee);
        [, $secondRow] = $this->uploadAndRead($second, $employee);

        $this->assertNotSame($firstRow->id, $secondRow->id, 'drafts are per admin');

        foreach ([[$first, $firstRow], [$second, $secondRow]] as [$admin, $row]) {
            $this->actingAs($admin, 'api')
                ->postJson("/admin/paper-uploads/{$row->id}/submit", $this->confirmPayload($row->refresh()))
                ->assertOk();
        }

        $this->assertSame(
            2,
            AdminPaperUpload::query()->where('user_id', $employee->id)->whereNotNull('submitted_at')->count()
        );
    }

    public function testPaperTypeOtherThanTheResidenceCardScanIsRejectedBeforeAnyFileIsStored(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        $this->actingAs($admin, 'api')
            ->postJson("/admin/users/{$employee->id}/paper-uploads", [
                'paper_type' => UserPaperType::Passport->value,
                'file' => $this->scanImage(),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('admin_paper_uploads', 0);
        $this->assertSame([], Storage::allFiles());
    }
}
