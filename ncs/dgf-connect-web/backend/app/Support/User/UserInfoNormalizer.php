<?php

namespace App\Support\User;

use App\Models\Traits\NormalizesDiacritics;

class UserInfoNormalizer
{
    use NormalizesDiacritics;

    public function address(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->removeDiacritics(preg_replace('/,\s*/', ' ', $value));
    }

    public function prefecture(?string $value): ?string
    {
        return $value !== null ? mb_strtolower(mb_trim($value)) : null;
    }
}
