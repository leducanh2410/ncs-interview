<?php

namespace App\ModelFilters;

class DocumentFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function id(string $id): DocumentFilter
    {
        return $this->where('id', $id);
    }

    public function ids(array $ids): DocumentFilter
    {
        return $this->whereIn('id', $ids);
    }

    public function title(string $title): DocumentFilter
    {
        return $this->whereLike('title', $title);
    }

    public function query(string $query): DocumentFilter
    {
        return $this->where(function ($builder) use ($query) {
            $builder->where('title', 'like', "%{$query}%");

            if (ctype_digit($query)) {
                $builder->orWhere('id', (int) $query);
            }
        });
    }

    public function workplace(int $workplaceId): DocumentFilter
    {
        if ($workplaceId === self::ALL_VALUE_INT) {
            return $this;
        }

        return $this->whereHas('users.workplaces', function ($query) use ($workplaceId) {
            $query->where('workplaces.id', $workplaceId);
        });
    }

    public function dates($dates): DocumentFilter
    {
        return $this->applyDateFilter($dates, 'created_at');
    }
}
