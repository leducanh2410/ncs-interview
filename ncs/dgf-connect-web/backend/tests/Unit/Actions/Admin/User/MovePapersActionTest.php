<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\MovePapersAction;
use App\Enums\ErrorCode;
use App\Enums\UserPaperItemFolder;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovePapersActionTest extends TestCase
{
    use RefreshDatabase;

    public function testItMovesSingleProcessedPaperItemBetweenCurrentAndOldFolders(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $result = (new MovePapersAction())($user, [$item->id], UserPaperItemFolder::Old->value);

        $this->assertNull($result->getError());
        $this->assertEquals(UserPaperItemFolder::Old->value, $item->fresh()->folder);
    }

    public function testItMovesMultipleProcessedPaperItemsBetweenCurrentAndOldFolders(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item1 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);
        $item2 = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $result = (new MovePapersAction())($user, [$item1->id, $item2->id], UserPaperItemFolder::Old->value);

        $this->assertNull($result->getError());
        $this->assertEquals(UserPaperItemFolder::Old->value, $item1->fresh()->folder);
        $this->assertEquals(UserPaperItemFolder::Old->value, $item2->fresh()->folder);
        $this->assertCount(2, $result->getData());
    }

    public function testItRejectsPaperItemsOwnedByAnotherUser(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUserPaper = UserPaper::factory()->create(['user_id' => $otherUser->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $otherUserPaper->id,
            'approved_at' => now(),
            'rejected_at' => null,
            'folder' => UserPaperItemFolder::Current->value,
        ]);

        $result = (new MovePapersAction())($user, [$item->id], UserPaperItemFolder::Old->value);

        $this->assertEquals(ErrorCode::NotFound, $result->getError());
        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(UserPaperItemFolder::Current->value, $item->fresh()->folder);
    }

    public function testItRejectsPendingPaperItem(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => null,
            'rejected_at' => null,
            'folder' => null,
        ]);

        $result = (new MovePapersAction())($user, [$item->id], UserPaperItemFolder::Old->value);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertNull($item->fresh()->folder);
    }

    public function testItRejectsPaperItemInRejectedFolder(): void
    {
        $user = User::factory()->create();
        $userPaper = UserPaper::factory()->create(['user_id' => $user->id]);
        $item = UserPaperItem::factory()->create([
            'user_paper_id' => $userPaper->id,
            'approved_at' => null,
            'rejected_at' => now(),
            'folder' => UserPaperItemFolder::Rejected->value,
        ]);

        $result = (new MovePapersAction())($user, [$item->id], UserPaperItemFolder::Current->value);

        $this->assertEquals(ErrorCode::UnprocessableEntity, $result->getError());
        $this->assertEquals(422, $result->getStatus());
        $this->assertEquals(UserPaperItemFolder::Rejected->value, $item->fresh()->folder);
    }
}
