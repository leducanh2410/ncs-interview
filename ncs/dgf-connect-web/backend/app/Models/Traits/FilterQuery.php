<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

trait FilterQuery
{
    public static function getFilteredQuery(
        array $conditions,
        ?array $relations = null,
        ?array $orderBy = null
    ): Builder {
        return static::query()
            ->when($relations, function (Builder $builder, $relations) {
                $builder->with($relations);
            })
            ->when($orderBy, function (Builder $builder, $orderBy) {
                foreach ($orderBy as $field => $direction) {
                    $builder->orderBy($field, $direction);
                }
            })
            ->filter($conditions);
    }

    public static function getFiltered(array $conditions, ?array $relations = null, ?array $orderBy = null): Collection
    {
        return static::getFilteredQuery($conditions, $relations, $orderBy)->get();
    }

    public static function getPaginateFiltered(
        array $conditions,
        ?array $relations = null,
        ?array $orderBy = null,
        ?int $pageSize = null
    ): LengthAwarePaginator {
        return static::getFilteredQuery($conditions, $relations, $orderBy)->paginateFilter($pageSize);
    }
}
