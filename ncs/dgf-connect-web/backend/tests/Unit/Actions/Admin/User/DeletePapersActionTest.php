<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\DeletePapersAction;
use App\Enums\ErrorCode;
use App\Enums\UserPaperItemFolder;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletePapersActionTest extends TestCase
{
    use RefreshDatabase;

    public function testItMarksPaperItemsAsDeletedInsteadOfHardDeleting(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => null,
        ]);
        $item2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Old->value,
            'deleted_at' => null,
        ]);

        $result = (new DeletePapersAction())($user, [$item1->id, $item2->id]);

        $this->assertNull($result->getError());
        $this->assertDatabaseHas('user_paper_items', [
            'id' => $item1->id,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => UserPaperItemFolder::Current->value,
        ]);
        $this->assertDatabaseHas('user_paper_items', [
            'id' => $item2->id,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => UserPaperItemFolder::Old->value,
        ]);
        $this->assertNotNull($item1->fresh()->deleted_at);
        $this->assertNotNull($item2->fresh()->deleted_at);
    }

    public function testItRejectsPaperItemOwnedByAnotherUser(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $otherUser->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
        ]);

        $result = (new DeletePapersAction())($user, [$item->id]);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
        $this->assertNull($item->fresh()->deleted_at);
    }
}
