<?php

namespace App\ModelFilters;

use Illuminate\Support\Carbon;

class SalaryRequestFilter extends AbstractFilter
{
    public function user($userId): SalaryRequestFilter
    {
        return $this->where('user_id', $userId);
    }

    public function status($status): SalaryRequestFilter
    {
        if ($status === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('status', $status);
    }

    public function month(string $month): SalaryRequestFilter
    {
        $startDate = Carbon::parse($month)->startOfMonth();
        $endDate = Carbon::parse($month)->endOfMonth();

        return $this->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function year(string $year): SalaryRequestFilter
    {
        // Carbon::parse() reads a bare four-digit string as a HH:MM time ("2025" becomes 20:25
        // today), which would silently collapse every such year onto the current one.
        $startDate = Carbon::createFromFormat('Y', $year)->startOfYear();
        $endDate = Carbon::createFromFormat('Y', $year)->endOfYear();

        return $this->whereBetween('created_at', [$startDate, $endDate]);
    }
}
