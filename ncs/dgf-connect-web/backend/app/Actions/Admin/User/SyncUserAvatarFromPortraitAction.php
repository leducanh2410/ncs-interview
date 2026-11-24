<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\UserPaperType;
use App\Models\User;
use App\Models\UserPaperItem;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SyncUserAvatarFromPortraitAction
{
    private const AVATAR_WIDTH = 400;

    private const AVATAR_FILE_PREFIX = 'avatar_';

    /**
     * Set the account avatar from the newest approved portrait image.
     *
     * The avatar is always overwritten, including one the employee picked themselves.
     */
    public function __invoke(User $user): ActionResult
    {
        $portrait = UserPaperItem::query()
            ->whereHas('userPaper', fn($q) => $q->where('user_id', $user->id))
            ->where('type', UserPaperType::PortraitImage->value)
            ->whereNotNull('approved_at')
            ->whereNull('rejected_at')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        if (!$portrait) {
            return new ActionResult(data: false);
        }

        if (!$portrait->file_path) {
            // Approved but pathless: bad legacy or imported data, and the only false
            // branch that would otherwise leave the caller nothing to look at.
            Log::warning('Cannot sync avatar, portrait has no file path', [
                'user_id' => $user->id,
                'portrait_id' => $portrait->id,
            ]);

            return new ActionResult(data: false);
        }

        // Naming the copy after the portrait keeps re-approvals idempotent: the same
        // portrait rewrites the same file instead of piling up copies.
        $directory = "users/{$user->id}";
        $fileName = self::AVATAR_FILE_PREFIX . "{$portrait->id}.jpg";
        $targetPath = "{$directory}/{$fileName}";
        $previousPath = $user->profile_picture;

        try {
            if (!Storage::exists($portrait->file_path)) {
                Log::warning('Cannot sync avatar, portrait file is missing', [
                    'user_id' => $user->id,
                    'portrait_id' => $portrait->id,
                ]);

                return new ActionResult(data: false);
            }

            $stored = Image::fromStorage($portrait->file_path)
                ->scale(width: self::AVATAR_WIDTH)
                ->toJpeg()
                ->quality(80)
                ->storeAs($directory, $fileName);

            if ($stored === false) {
                Log::error('Failed to store the avatar copied from the portrait', [
                    'user_id' => $user->id,
                    'portrait_id' => $portrait->id,
                    'target_path' => $targetPath,
                ]);

                return new ActionResult(data: false);
            }
        } catch (Throwable $e) {
            Log::error('Failed to build avatar from portrait', [
                'user_id' => $user->id,
                'portrait_id' => $portrait->id,
                'error' => $e->getMessage(),
            ]);

            return new ActionResult(data: false);
        }

        // What the disk actually wrote, not the path guessed above.
        $user->update(['profile_picture' => $stored]);

        $this->deletePreviousAvatarFile($previousPath, $stored);

        return new ActionResult(data: true);
    }

    /**
     * Drop the copy this action made last time, and nothing else.
     *
     * Overwriting the pointer is intended, but deleting the file behind it is not
     * ours to do when somebody else put it there: a picture the employee uploaded
     * through their profile is unrecoverable, and legacy avatars can still point
     * straight at a document or a user image. Only `avatar_*` files sitting next to
     * the new one are ours, and no other writer produces that name - uploads are
     * stored under a random hashName().
     */
    private function deletePreviousAvatarFile(?string $previousPath, string $targetPath): void
    {
        if (!$previousPath || $previousPath === $targetPath) {
            return;
        }

        $isOwnCopy =
            dirname($previousPath) === dirname($targetPath) &&
            Str::startsWith(basename($previousPath), self::AVATAR_FILE_PREFIX);

        if (!$isOwnCopy) {
            return;
        }

        Storage::delete($previousPath);
    }
}
