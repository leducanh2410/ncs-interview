<?php

namespace App\Actions\Admin\Poll;

use App\Models\Poll;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class GetPollsAction
{
    /**
     * List polls with optional filtering.
     */
    public function __invoke(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Poll::query()->with('workplace')->withCount('responses')->orderBy('id', 'desc');

        if (Arr::has($filters, 'type')) {
            $query->where('type', $filters['type']);
        }

        if (Arr::has($filters, 'workplace_id')) {
            $query->where('workplace_id', $filters['workplace_id']);
        }

        if (Arr::has($filters, 'is_active')) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->paginate($perPage);
    }
}
