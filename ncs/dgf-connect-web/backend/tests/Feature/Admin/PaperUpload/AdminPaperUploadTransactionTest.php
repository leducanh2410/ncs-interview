<?php

namespace Tests\Feature\Admin\PaperUpload;

use App\Actions\Admin\PaperUpload\UploadAdminPaperAction;
use App\Enums\UserPaperType;
use App\Models\AdminPaperUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

/**
 * The upload writes a file and a row. A failure between the two must not leave either
 * half behind — an orphan copy of somebody's identity document least of all.
 */
class AdminPaperUploadTransactionTest extends TestCase
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

    public function testAFailureAfterTheFileIsStoredLeavesNoRowAndNoOrphanFile(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        // Fail inside the transaction, after handleImageUpload() has already written.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `admin_paper_uploads`')) {
                throw new RuntimeException('insert exploded');
            }
        });

        $this->assertThrows(
            fn() => app(UploadAdminPaperAction::class)(
                $employee,
                UserPaperType::ForeignerCardScan,
                $this->scanImage(),
                $admin
            ),
            RuntimeException::class
        );

        $this->assertDatabaseCount('admin_paper_uploads', 0);
        $this->assertSame([], Storage::allFiles(), 'the file written for the rolled-back row is gone');
    }

    public function testAFailedRetakeLeavesThePreviousDraftAndItsImageIntact(): void
    {
        $admin = $this->uploadingAdmin();
        $employee = $this->employee();

        [, $first] = $this->uploadAndRead($admin, $employee);
        $firstPath = $first->file_path;
        Storage::assertExists($firstPath);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `admin_paper_uploads`')) {
                throw new RuntimeException('retake insert exploded');
            }
        });

        $this->assertThrows(
            fn() => app(UploadAdminPaperAction::class)(
                $employee,
                UserPaperType::ForeignerCardScan,
                $this->scanImage(),
                $admin
            ),
            RuntimeException::class
        );

        // The replaced draft's delete rolled back with everything else, and its file was
        // only ever scheduled for deletion after commit — a commit that never happened.
        $this->assertDatabaseHas('admin_paper_uploads', ['id' => $first->id]);
        Storage::assertExists($firstPath);
        $this->assertSame(1, AdminPaperUpload::query()->count());
    }
}
