<?php

namespace Tests\Feature\Admin\PaperReconciliation;

use App\Actions\Admin\PaperReconciliation\ResolveEmployeeSideAction;
use App\Enums\UserPaperItemFolder;
use App\Enums\UserPaperType;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAdminPaperUploadFixtures;
use Tests\TestCase;

/**
 * The Current_Item_Rule was extracted so two surfaces could share it. This test is the
 * reason the extraction exists: the employee detail page and the reconciliation popup
 * must show the same picture for the same person, or they contradict each other.
 */
class CurrentPaperItemSelectorSharingTest extends TestCase
{
    use BuildsAdminPaperUploadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake();
    }

    public function testAPendingUploadWinsOverAnOlderApprovedOneOnBothSurfaces(): void
    {
        $employee = $this->employee();
        $paper = UserPaper::query()->create(['user_id' => $employee->id]);

        $approved = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::ForeignerCardScan->value,
            'folder' => UserPaperItemFolder::Current->value,
            'approved_at' => now()->subMonth(),
        ]);
        $pending = UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::ForeignerCardScan->value,
            'folder' => UserPaperItemFolder::Current->value,
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $resolved = app(ResolveEmployeeSideAction::class)([$employee->id]);

        $this->assertSame(
            $pending->id,
            $resolved[$employee->id]['scan_item']?->id,
            'a still-pending upload is the current image, as the employee detail page also decides'
        );
        $this->assertNotSame($approved->id, $resolved[$employee->id]['scan_item']?->id);
    }

    public function testAnEmployeeWithNoScanResolvesToNullWithoutAffectingTheFields(): void
    {
        $employee = $this->employeeMatchingTheScan();

        $resolved = app(ResolveEmployeeSideAction::class)([$employee->id]);

        $this->assertNull($resolved[$employee->id]['scan_item']);
        $this->assertSame('AB12345678CD', $resolved[$employee->id]['fields']['residence_number']);
        $this->assertFalse($resolved[$employee->id]['residence_missing']);
    }

    public function testADeletedItemIsNeverChosen(): void
    {
        $employee = $this->employee();
        $paper = UserPaper::query()->create(['user_id' => $employee->id]);

        UserPaperItem::factory()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::ForeignerCardScan->value,
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => now(),
        ]);

        $resolved = app(ResolveEmployeeSideAction::class)([$employee->id]);

        $this->assertNull($resolved[$employee->id]['scan_item']);
    }
}
