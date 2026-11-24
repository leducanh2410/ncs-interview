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
        array|string|null $orderBy = null
    ): Builder {
        return static::query()
            ->when($relations, function (Builder $builder, $relations) {
                $builder->with($relations);
            })
            ->when($orderBy, function (Builder $builder, $orderBy) {
                if (is_string($orderBy)) {
                    $sorts = explode(',', $orderBy);

                    $model = $builder->getModel();
                    $allowedColumns = property_exists($model, 'sortable')
                        ? $model->sortable
                        : ['*'];

                    foreach ($sorts as $sort) {
                        $direction = 'asc';
                        if (str_starts_with($sort, '-')) {
                            $direction = 'desc';
                            $sort = substr($sort, 1);
                        }

                        if (in_array('*', $allowedColumns) || in_array($sort, $allowedColumns)) {
                            $builder->orderBy($sort, $direction);
                        } else {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'sort' => __('validation.in', ['attribute' => 'sort']) ?: "Invalid sort column '{$sort}'.",
                            ]);
                        }
                    }
                } elseif (is_array($orderBy)) {
                    foreach ($orderBy as $field => $direction) {
                        $builder->orderBy($field, $direction);
                    }
                }
            })
            ->filter($conditions);
    }

    public static function getFiltered(array $conditions, ?array $relations = null, array|string|null $orderBy = null): Collection
    {
        return static::getFilteredQuery($conditions, $relations, $orderBy)->get();
    }

    public static function getPaginateFiltered(
        array $conditions,
        ?array $relations = null,
        array|string|null $orderBy = null,
        ?int $pageSize = null
    ): LengthAwarePaginator {
        return static::getFilteredQuery($conditions, $relations, $orderBy)->paginateFilter($pageSize);
    }
}
