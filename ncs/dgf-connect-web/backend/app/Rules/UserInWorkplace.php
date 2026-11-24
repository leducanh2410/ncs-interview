<?php

namespace App\Rules;

use App\Models\WorkplaceUser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

readonly class UserInWorkplace implements ValidationRule
{
    private int $workplaceId;

    public function __construct(int $workplaceId)
    {
        $this->workplaceId = $workplaceId;
    }

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = WorkplaceUser::query()->where('workplace_id', $this->workplaceId)->where('user_id', $value)->exists();
        if (!$exists) {
            $fail('User is not a member of the workplace.');
        }
    }
}
