<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class CompanyFilter extends ModelFilter
{
    /**
     * Filter by search term.
     * Searches in company_name, contact_email, contact_phone, and contact name.
     */
    public function search(string $val): CompanyFilter
    {
        return $this->where(function ($query) use ($val) {
            $query->where('company_name', 'LIKE', "%$val%")
                ->orWhere('contact_email', 'LIKE', "%$val%")
                ->orWhere('contact_phone', 'LIKE', "%$val%")
                ->orWhere('contact_first_name', 'LIKE', "%$val%")
                ->orWhere('contact_last_name', 'LIKE', "%$val%")
                ->orWhereRaw("CONCAT(contact_first_name, ' ', contact_last_name) LIKE ?", ["%$val%"]);
        });
    }

    /**
     * Filter by contract status.
     */
    public function status(string $val): CompanyFilter
    {
        return $this->where('status', $val);
    }
}
