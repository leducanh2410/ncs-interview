<?php

namespace Tests\Unit\Jobs;

use App\Enums\UserPaperItemFolder;
use App\Jobs\DeleteOldUserPaperItems;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteOldUserPaperItemsTest extends TestCase
{
    use RefreshDatabase;

    public function testItDeletesOldDeletedPapersAndFiles(): void
    {
        Storage::fake();

        $oldDeletedItem = UserPaperItem::factory()->create([
            'file_path' => 'papers/old-deleted.jpg',
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_at' => now()->subYear()->subDay(),
        ]);
        Storage::put($oldDeletedItem->file_path, 'content');

        $recentDeletedItem = UserPaperItem::factory()->create([
            'file_path' => 'papers/recent-deleted.jpg',
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_at' => now()->subMonths(6),
        ]);
        Storage::put($recentDeletedItem->file_path, 'content');

        $activeItem = UserPaperItem::factory()->create([
            'file_path' => 'papers/active.jpg',
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => null,
        ]);
        Storage::put($activeItem->file_path, 'content');

        (new DeleteOldUserPaperItems())->handle();

        $this->assertDatabaseMissing('user_paper_items', ['id' => $oldDeletedItem->id]);
        Storage::assertMissing($oldDeletedItem->file_path);

        $this->assertDatabaseHas('user_paper_items', ['id' => $recentDeletedItem->id]);
        Storage::assertExists($recentDeletedItem->file_path);

        $this->assertDatabaseHas('user_paper_items', ['id' => $activeItem->id]);
        Storage::assertExists($activeItem->file_path);
    }
}
