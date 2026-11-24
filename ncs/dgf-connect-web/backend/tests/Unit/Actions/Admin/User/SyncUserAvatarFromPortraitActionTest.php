<?php

namespace Tests\Unit\Actions\Admin\User;

use App\Actions\Admin\User\SyncUserAvatarFromPortraitAction;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserImage;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SyncUserAvatarFromPortraitActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    public function testSetsAvatarFromNewestApprovedPortrait(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $oldPortrait = $this->makePortrait($user);
        $newPortrait = $this->makePortrait($user);

        $result = $this->action()($user);

        $this->assertTrue($result->getData());
        $user->refresh();
        $this->assertSame("users/{$user->id}/avatar_{$newPortrait->id}.jpg", $user->profile_picture);
        $this->assertTrue(Storage::exists($user->profile_picture));

        // The avatar is a copy, so both document files must survive untouched
        $this->assertNotSame($newPortrait->file_path, $user->profile_picture);
        $this->assertTrue(Storage::exists($newPortrait->file_path));
        $this->assertTrue(Storage::exists($oldPortrait->file_path));
    }

    public function testScalesTheAvatarDownToFourHundredPixelsWide(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $this->makePortrait($user, width: 1200, height: 900);

        $this->action()($user);

        $avatar = Image::fromStorage($user->refresh()->profile_picture);
        $this->assertSame(400, $avatar->width());
        $this->assertSame(300, $avatar->height());
    }

    public function testLeavesAPortraitNarrowerThanTheAvatarWidthAtItsOriginalSize(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $portrait = $this->makePortrait($user, width: 200, height: 260);

        $this->action()($user);

        $avatar = Image::fromStorage($user->refresh()->profile_picture);
        $this->assertSame(200, $avatar->width());
        $this->assertSame(260, $avatar->height());
        $this->assertLessThanOrEqual(
            Storage::size($portrait->file_path) * 2,
            Storage::size($user->profile_picture),
            'a small portrait must not be upscaled into a heavier avatar'
        );
    }

    public function testOverwritesAnAvatarTheUserPickedThemselvesButKeepsTheOldFile(): void
    {
        $user = User::factory()->create();
        $ownAvatar = "users/{$user->id}/OaX2mQ7bTfKcZ1pRs9vLdE4hNu6jYwGi3ArBnKtQ.jpg";
        Storage::put($ownAvatar, 'own-avatar-bytes');
        $user->update(['profile_picture' => $ownAvatar]);

        $portrait = $this->makePortrait($user);

        $this->action()($user);

        $user->refresh();
        $this->assertSame("users/{$user->id}/avatar_{$portrait->id}.jpg", $user->profile_picture);

        // The pointer moves, but a picture the employee uploaded is not ours to delete
        $this->assertTrue(Storage::exists($ownAvatar));
    }

    public function testDropsTheCopyItMadeForAnEarlierPortrait(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $firstPortrait = $this->makePortrait($user);

        $this->action()($user);
        $firstAvatar = $user->refresh()->profile_picture;
        $this->assertSame("users/{$user->id}/avatar_{$firstPortrait->id}.jpg", $firstAvatar);

        $renewedPortrait = $this->makePortrait($user);
        $this->action()($user->refresh());

        $this->assertSame("users/{$user->id}/avatar_{$renewedPortrait->id}.jpg", $user->refresh()->profile_picture);
        $this->assertFalse(Storage::exists($firstAvatar), 'the copy this action made must not pile up');
    }

    public function testKeepsAPreviousAvatarFileThatIsStillADocument(): void
    {
        $user = User::factory()->create();
        $portrait = $this->makePortrait($user);
        // A legacy avatar pointing straight at the document file must not be deleted
        $user->update(['profile_picture' => $portrait->file_path]);

        $this->action()($user);

        $this->assertTrue(Storage::exists($portrait->file_path));
        $this->assertSame("users/{$user->id}/avatar_{$portrait->id}.jpg", $user->refresh()->profile_picture);
    }

    public function testKeepsAPreviousAvatarFileThatIsStillAUserImage(): void
    {
        $user = User::factory()->create();
        $imagePath = "users/{$user->id}/gallery.jpg";
        Storage::put($imagePath, 'gallery-bytes');
        UserImage::query()->create(['user_id' => $user->id, 'path' => $imagePath]);
        $user->update(['profile_picture' => $imagePath]);

        $this->makePortrait($user);

        $this->action()($user);

        $this->assertTrue(Storage::exists($imagePath));
    }

    public function testIgnoresRejectedAndDeletedPortraits(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $approved = $this->makePortrait($user);
        $this->makePortrait($user, attributes: ['rejected_at' => now(), 'approved_at' => null]);
        $this->makePortrait($user, attributes: ['deleted_at' => now()]);

        $this->action()($user);

        $this->assertSame("users/{$user->id}/avatar_{$approved->id}.jpg", $user->refresh()->profile_picture);
    }

    public function testIgnoresPortraitsOfOtherUsersAndOtherPaperTypes(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $this->makePortrait($user, attributes: ['type' => UserPaperType::Passport->value]);
        $this->makePortrait(User::factory()->create());

        $result = $this->action()($user);

        $this->assertFalse($result->getData());
        $this->assertNull($user->refresh()->profile_picture);
    }

    public function testLeavesTheAvatarUnchangedWhenThePortraitFileIsMissing(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $portrait = $this->makePortrait($user);
        Storage::delete($portrait->file_path);

        $result = $this->action()($user);

        $this->assertFalse($result->getData());
        $this->assertNull($user->refresh()->profile_picture);
    }

    public function testLogsAndLeavesTheAvatarWhenThePortraitHasNoFilePath(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $this->makePortrait($user, attributes: ['file_path' => null]);

        Log::shouldReceive('warning')
            ->once()
            ->with('Cannot sync avatar, portrait has no file path', Mockery::type('array'));

        $result = $this->action()($user);

        $this->assertFalse($result->getData());
        $this->assertNull($user->refresh()->profile_picture);
    }

    public function testKeepsTheCurrentAvatarWhenTheNewFileCannotBeWritten(): void
    {
        $user = User::factory()->create();
        $ownAvatar = 'avatars/own-avatar.jpg';
        Storage::put($ownAvatar, 'own-avatar-bytes');
        $user->update(['profile_picture' => $ownAvatar]);

        $portrait = $this->makePortrait($user);

        // A file sitting where the avatar directory belongs makes the write fail, and
        // disks are configured with `throw => false`, so it comes back as false
        Storage::put("users/{$user->id}", 'not-a-directory');

        $result = $this->action()($user);

        $this->assertFalse($result->getData());
        $this->assertSame($ownAvatar, $user->refresh()->profile_picture);
        $this->assertTrue(Storage::exists($ownAvatar));
        $this->assertTrue(Storage::exists($portrait->file_path));
    }

    public function testRunningTwiceOnTheSamePortraitKeepsTheAvatarInPlace(): void
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $portrait = $this->makePortrait($user);

        $this->action()($user);
        $firstPath = $user->refresh()->profile_picture;

        $this->action()($user);

        $user->refresh();
        $this->assertSame($firstPath, $user->profile_picture);
        $this->assertSame("users/{$user->id}/avatar_{$portrait->id}.jpg", $user->profile_picture);
        $this->assertTrue(Storage::exists($user->profile_picture));
    }

    private function action(): SyncUserAvatarFromPortraitAction
    {
        return app(SyncUserAvatarFromPortraitAction::class);
    }

    private function makePortrait(
        User $user,
        int $width = 200,
        int $height = 200,
        array $attributes = []
    ): UserPaperItem {
        $paper = UserPaper::query()->firstOrCreate(['user_id' => $user->id]);

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();

        $path = 'papers/' . fake()->uuid() . '.jpg';
        Storage::put($path, $bytes);

        return UserPaperItem::query()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => basename($path),
            'file_path' => $path,
            'approved_at' => now(),
            'rejected_at' => null,
            'deleted_at' => null,
            ...$attributes,
        ]);
    }
}
