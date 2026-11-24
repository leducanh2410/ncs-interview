<?php

namespace App\ModelFilters;

class SalaryFilter extends AbstractFilter
{
    public function user($userId): SalaryFilter
    {
        return $this->where('user_id', $userId);
    }

    public function month(string $month): SalaryFilter
    {
        return $this->where('month', $month);
    }
}
