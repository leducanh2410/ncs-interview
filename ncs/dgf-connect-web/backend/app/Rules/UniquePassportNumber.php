<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniquePassportNumber implements ValidationRule
{
    private ?int $currentUserId;

    public function __construct($currentUserId = null)
    {
        $this->currentUserId = $currentUserId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (null === $value || mb_trim($value) === '') {
            return;
        }

        $query = DB::table('user_infos')->where('passport_number', $value);

        if ($this->currentUserId) {
            $query->where('user_id', '!=', $this->currentUserId);
        }

        if ($query->exists()) {
            $fail('The passport number has already been taken by another user.');
        }
    }
}
