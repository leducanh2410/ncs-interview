<?php

namespace Tests\Feature\Console;

use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaper;
use App\Models\UserPaperItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillPortraitAvatarsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    public function testSetsTheAvatarForUsersApprovedBeforeTheFeatureExisted(): void
    {
        $user = $this->userWithApprovedPortrait();
        $portrait = $user->paper->items()->firstOrFail();

        $this->artisan('avatar:backfill-portraits')->assertSuccessful();

        $this->assertSame("users/{$user->id}/avatar_{$portrait->id}.jpg", $user->refresh()->profile_picture);
        $this->assertTrue(Storage::exists($user->profile_picture));
    }

    /**
     * The whole reason the command filters on a null picture: the sync action always
     * overwrites, and a batch job must not swap out a picture an employee chose.
     */
    public function testLeavesAPictureTheEmployeeChoseThemselvesUntouched(): void
    {
        $user = $this->userWithApprovedPortrait();
        $ownPicture = "users/{$user->id}/own-avatar.jpg";
        Storage::put($ownPicture, 'own-avatar-bytes');
        $user->update(['profile_picture' => $ownPicture]);

        $this->artisan('avatar:backfill-portraits')->assertSuccessful();

        $this->assertSame($ownPicture, $user->refresh()->profile_picture);
        $this->assertTrue(Storage::exists($ownPicture));
    }

    public function testLeavesUsersWithoutAnApprovedPortraitAlone(): void
    {
        $pending = $this->userWithApprovedPortrait(approved: false);
        $none = User::factory()->create(['profile_picture' => null]);

        $this->artisan('avatar:backfill-portraits')->assertSuccessful();

        $this->assertNull($pending->refresh()->profile_picture);
        $this->assertNull($none->refresh()->profile_picture);
    }

    /**
     * Old data can carry an approved portrait whose file was cleaned up or corrupted.
     * The run must not report success, or nobody will ever look at those employees again.
     */
    public function testReportsFailureWhenThePortraitFileIsGone(): void
    {
        $user = $this->userWithApprovedPortrait();
        $portrait = $user->paper->items()->firstOrFail();
        Storage::delete($portrait->file_path);

        $this->artisan('avatar:backfill-portraits')->assertFailed();

        $this->assertNull($user->refresh()->profile_picture);
    }

    public function testASingleUnusablePortraitDoesNotStopTheRest(): void
    {
        $broken = $this->userWithApprovedPortrait();
        Storage::delete($broken->paper->items()->firstOrFail()->file_path);
        $healthy = $this->userWithApprovedPortrait();

        $this->artisan('avatar:backfill-portraits')->assertFailed();

        $this->assertNull($broken->refresh()->profile_picture);
        $this->assertNotNull($healthy->refresh()->profile_picture);
    }

    public function testDryRunWritesNothingAndKeepsEmployeeEmailsOutOfTheOutput(): void
    {
        $user = $this->userWithApprovedPortrait();

        $this->artisan('avatar:backfill-portraits', ['--dry-run' => true])
            ->expectsOutputToContain("candidate #{$user->id}")
            ->doesntExpectOutputToContain($user->email)
            ->assertSuccessful();

        $this->assertNull($user->refresh()->profile_picture);
    }

    public function testIsSafeToRunTwice(): void
    {
        $user = $this->userWithApprovedPortrait();

        $this->artisan('avatar:backfill-portraits')->assertSuccessful();
        $firstAvatar = $user->refresh()->profile_picture;

        $this->artisan('avatar:backfill-portraits')->assertSuccessful();

        $this->assertSame($firstAvatar, $user->refresh()->profile_picture);
        $this->assertTrue(Storage::exists($firstAvatar));
    }

    public function testRejectsANonPositiveChunk(): void
    {
        $this->artisan('avatar:backfill-portraits', ['--chunk' => 0])->assertFailed();
    }

    private function userWithApprovedPortrait(bool $approved = true): User
    {
        $user = User::factory()->create(['profile_picture' => null]);
        $paper = UserPaper::query()->create(['user_id' => $user->id]);

        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();

        $path = 'papers/' . fake()->uuid() . '.jpg';
        Storage::put($path, $bytes);

        UserPaperItem::query()->create([
            'user_paper_id' => $paper->id,
            'type' => UserPaperType::PortraitImage->value,
            'file_name' => basename($path),
            'file_path' => $path,
            'approved_at' => $approved ? now() : null,
        ]);

        return $user;
    }
}
