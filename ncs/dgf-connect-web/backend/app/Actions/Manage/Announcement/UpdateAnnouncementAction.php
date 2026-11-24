<?php

namespace App\Actions\Manage\Announcement;

use App\Actions\ActionResult;
use App\Models\Announcement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UpdateAnnouncementAction
{
    public function __construct(
        private readonly SyncAnnouncementTranslationsAction $syncTranslations,
        private readonly StoreAnnouncementImageAction $storeImage
    ) {}

    public function __invoke(Announcement $announcement, array $values): ActionResult
    {
        $storedImagePath = null;

        try {
            $removedImagePath = DB::transaction(function () use ($announcement, $values, &$storedImagePath): ?string {
                $announcement->fill([
                    'title' => $values['title'],
                    'content' => $values['content'],
                    'start_at' => $values['start_at'],
                    'end_at' => $values['end_at'],
                    'is_active' => (bool) $values['is_active'],
                ]);

                $removedImagePath = null;
                $image = $values['image'] ?? null;
                $shouldRemoveImage = !($image instanceof UploadedFile) && (bool) ($values['remove_image'] ?? false);
                if ($shouldRemoveImage && $announcement->image_path) {
                    $removedImagePath = $announcement->image_path;
                    $announcement->image_path = null;
                }

                $announcement->save();
                $announcement->workplaces()->sync($values['workplace_ids']);
                ($this->syncTranslations)($announcement, $values);

                if ($image instanceof UploadedFile) {
                    $removedImagePath = ($this->storeImage)($announcement, $image);
                    $storedImagePath = $announcement->image_path;
                }

                return $removedImagePath;
            });
        } catch (Throwable $exception) {
            if ($storedImagePath) {
                Storage::delete($storedImagePath);
            }

            throw $exception;
        }

        if ($removedImagePath) {
            Storage::delete($removedImagePath);
        }

        return new ActionResult(data: $announcement->fresh(['workplaces', 'translations']));
    }
}
