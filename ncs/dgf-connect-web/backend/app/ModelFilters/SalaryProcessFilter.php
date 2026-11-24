<?php

namespace App\ModelFilters;

class SalaryProcessFilter extends AbstractFilter
{
    public function status(string $status): SalaryProcessFilter
    {
        return $this->where('status', $status);
    }

    public function month(string $month): SalaryProcessFilter
    {
        return $this->where('month', $month);
    }

    public function workplace(int $workplaceId): SalaryProcessFilter
    {
        return $this->where('workplace_id', $workplaceId);
    }
}
