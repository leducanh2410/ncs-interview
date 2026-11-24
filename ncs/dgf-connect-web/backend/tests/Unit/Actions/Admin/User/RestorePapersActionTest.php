<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\RestorePapersAction;
use App\Enums\ErrorCode;
use App\Enums\UserPaperItemFolder;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestorePapersActionTest extends TestCase
{
    use RefreshDatabase;

    public function testItRestoresDeletedPaperItemsToPreviousFolder(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => UserPaperItemFolder::Old->value,
            'deleted_at' => now(),
        ]);
        $item2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => now(),
        ]);

        $result = (new RestorePapersAction())($user, [$item1->id, $item2->id]);

        $this->assertNull($result->getError());
        $this->assertEquals(UserPaperItemFolder::Old->value, $item1->fresh()->folder);
        $this->assertEquals(UserPaperItemFolder::Current->value, $item2->fresh()->folder);
        $this->assertNull($item1->fresh()->deleted_from_folder);
        $this->assertNull($item2->fresh()->deleted_from_folder);
        $this->assertNull($item1->fresh()->deleted_at);
        $this->assertNull($item2->fresh()->deleted_at);
    }

    public function testItRestoresDeletedPaperItemToCurrentFolderWhenNoPreviousFolder(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => null,
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Deleted->value,
            'deleted_from_folder' => null,
            'deleted_at' => now(),
        ]);

        $result = (new RestorePapersAction())($user, [$item->id]);

        $this->assertNull($result->getError());
        $this->assertEquals(UserPaperItemFolder::Current->value, $item->fresh()->folder);
        $this->assertNull($item->fresh()->deleted_at);
    }

    public function testItRejectsRestoringNonDeletedPaperItem(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'folder' => UserPaperItemFolder::Current->value,
            'deleted_at' => null,
        ]);

        $result = (new RestorePapersAction())($user, [$item->id]);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertEquals(UserPaperItemFolder::Current->value, $item->fresh()->folder);
    }
}
