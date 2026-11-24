<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends AbstractModel
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getPayableAmount(): int
    {
        if ($this->is_finalized) {
            return (int) max($this->total_amount - $this->total_paid - $this->total_deductions - $this->total_fee, 0);
        }

        return (int) max(
            floor($this->total_amount * 0.8 - $this->total_paid - $this->total_deductions - $this->total_fee),
            0
        );
    }
}
