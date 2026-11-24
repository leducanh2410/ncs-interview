<?php

namespace App\ModelFilters;

class DocumentRequiredConfigFilter extends AbstractFilter
{
    public function scope(string $scope): self
    {
        if ($scope === 'system') {
            return $this->whereNull('workplace_id');
        }

        if ($scope === 'workplace') {
            return $this->whereNotNull('workplace_id');
        }

        return $this;
    }

    public function workplace($workplaceId): self
    {
        if ($workplaceId === null || $workplaceId === '') {
            return $this->whereNull('workplace_id');
        }

        return $this->where('workplace_id', $workplaceId);
    }

    public function is_active(bool $isActive): self
    {
        return $this->where('is_active', $isActive);
    }
}
