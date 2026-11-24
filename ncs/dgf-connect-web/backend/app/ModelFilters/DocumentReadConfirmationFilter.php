<?php

namespace App\ModelFilters;

class DocumentReadConfirmationFilter extends AbstractFilter
{
    public function user(int $id): self
    {
        return $this->where('user_id', $id);
    }

    public function config(int $configId): self
    {
        return $this->where('document_required_config_id', $configId);
    }

    public function workplace($workplaceId): self
    {
        if ($workplaceId === null || $workplaceId === '') {
            return $this->whereHas('config', fn($q) => $q->whereNull('workplace_id'));
        }

        return $this->whereHas('config', fn($q) => $q->where('workplace_id', $workplaceId));
    }

    public function name(string $name): self
    {
        return $this->whereHas('user', function ($q) use ($name) {
            $q->where(function ($q2) use ($name) {
                $q2->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$name}%"])->orWhere(
                    'short_name',
                    'like',
                    "%{$name}%"
                );
            });
        });
    }

    public function staffNo(string $staffNo): self
    {
        return $this->whereHas('user', fn($q) => $q->where('staff_no', 'LIKE', "%{$staffNo}%"));
    }
}
