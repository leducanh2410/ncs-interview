<?php

namespace App\Actions\Manage\Announcement;

use App\Helpers\ImageHelper;
use App\Models\Announcement;
use Illuminate\Http\UploadedFile;

class StoreAnnouncementImageAction
{
    public const MAX_WIDTH = 1200;

    public function __invoke(Announcement $announcement, UploadedFile $image): ?string
    {
        $previousPath = $announcement->image_path;
        $announcement->image_path = ImageHelper::handleImageUpload(
            $image,
            "announcements/{$announcement->id}",
            self::MAX_WIDTH
        );
        $announcement->save();

        return $previousPath;
    }
}
