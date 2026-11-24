<?php

namespace App\Actions\User\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementTranslation;
use App\Models\User;
use Illuminate\Support\Collection;

class GetActiveAnnouncementsForUserAction
{
    public function __invoke(User $user): Collection
    {
        $workplaceIds = $user->workplaces()->pluck('workplaces.id')->all();

        if ($workplaceIds === []) {
            return collect();
        }

        return Announcement::query()
            ->validAt(now())
            ->forWorkplaceIds($workplaceIds)
            ->with('translations')
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn(Announcement $announcement): array => $this->serialize($announcement))
            ->values();
    }

    private function serialize(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'content' => $announcement->content,
            'image_url' => $announcement->image_url,
            'start_at' => $announcement->start_at->format('Y-m-d H:i:s'),
            'end_at' => $announcement->end_at->format('Y-m-d H:i:s'),
            'translations' => $announcement->translations
                ->map(
                    fn(AnnouncementTranslation $translation): array => [
                        'lang' => $translation->lang,
                        'title' => $translation->title,
                        'content' => $translation->content,
                    ]
                )
                ->values()
                ->all(),
        ];
    }
}
