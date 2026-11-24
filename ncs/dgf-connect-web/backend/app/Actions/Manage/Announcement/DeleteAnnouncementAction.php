<?php

namespace App\Actions\Manage\Announcement;

use App\Actions\ActionResult;
use App\Models\Announcement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteAnnouncementAction
{
    public function __invoke(Announcement $announcement): ActionResult
    {
        $imagePath = $announcement->image_path;

        DB::transaction(function () use ($announcement): void {
            $announcement->image_path = null;
            $announcement->save();
            $announcement->delete();
        });

        if ($imagePath) {
            Storage::delete($imagePath);
        }

        return new ActionResult();
    }
}
