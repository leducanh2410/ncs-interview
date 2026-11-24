<?php

namespace App\Actions\Manage\Announcement;

use App\Enums\AnnouncementState;
use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class GetAnnouncementsAction
{
    public function __invoke(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Announcement::query()
            ->with(['workplaces', 'translations'])
            ->orderByDesc('start_at')
            ->orderByDesc('id');

        $workplaceId = Arr::get($filters, 'workplace_id');
        if ($workplaceId) {
            $query->forWorkplaceIds([(int) $workplaceId]);
        }

        $keyword = mb_trim((string) Arr::get($filters, 'keyword', ''));
        if ($keyword !== '') {
            $keyword = addcslashes($keyword, '%_\\');
            $query->where(function (Builder $titleQuery) use ($keyword): void {
                $titleQuery
                    ->where('title', 'like', '%' . $keyword . '%')
                    ->orWhereHas(
                        'translations',
                        fn(Builder $translation) => $translation->where('title', 'like', '%' . $keyword . '%')
                    );
            });
        }

        $state = Arr::get($filters, 'state');
        if ($state) {
            $this->applyState($query, AnnouncementState::from($state));
        }

        return $query->paginate($perPage);
    }

    private function applyState(Builder $query, AnnouncementState $state): void
    {
        $now = now();

        match ($state) {
            AnnouncementState::Inactive => $query->where('is_active', false),
            AnnouncementState::Scheduled => $query->where('is_active', true)->where('start_at', '>', $now),
            AnnouncementState::Active => $query->validAt($now),
            AnnouncementState::Expired => $query->where('is_active', true)->where('end_at', '<=', $now),
        };
    }
}
