<?php

namespace App\ModelFilters;

class WorkplaceFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function id(string $id): WorkplaceFilter
    {
        return $this->where('id', $id);
    }

    public function name(string $name): WorkplaceFilter
    {
        return $this->whereLike('name', $name);
    }
}
