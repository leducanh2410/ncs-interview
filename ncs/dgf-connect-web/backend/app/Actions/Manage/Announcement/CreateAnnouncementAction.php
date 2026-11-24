<?php

namespace App\Actions\Manage\Announcement;

use App\Actions\ActionResult;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CreateAnnouncementAction
{
    public function __construct(
        private readonly SyncAnnouncementTranslationsAction $syncTranslations,
        private readonly StoreAnnouncementImageAction $storeImage
    ) {}

    public function __invoke(array $values, User $actor): ActionResult
    {
        $storedImagePath = null;

        try {
            $announcement = DB::transaction(function () use ($values, $actor, &$storedImagePath): Announcement {
                $announcement = Announcement::create([
                    'title' => $values['title'],
                    'content' => $values['content'],
                    'start_at' => $values['start_at'],
                    'end_at' => $values['end_at'],
                    'is_active' => (bool) $values['is_active'],
                    'created_by' => $actor->id,
                ]);

                $announcement->workplaces()->sync($values['workplace_ids']);
                ($this->syncTranslations)($announcement, $values);

                $image = $values['image'] ?? null;
                if ($image instanceof UploadedFile) {
                    ($this->storeImage)($announcement, $image);
                    $storedImagePath = $announcement->image_path;
                }

                return $announcement;
            });
        } catch (Throwable $exception) {
            if ($storedImagePath) {
                Storage::delete($storedImagePath);
            }

            throw $exception;
        }

        return new ActionResult(data: $announcement->fresh(['workplaces', 'translations']));
    }
}
