<?php

namespace App\Rules;

use App\Models\UserInfo;
use App\Support\Residence\ResidenceSlotResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class UniqueResidenceNumber implements ValidationRule, ValidatorAwareRule
{
    protected Validator $validator;

    private ?int $currentUserId;

    public function __construct($currentUserId = null)
    {
        $this->currentUserId = $currentUserId;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (null === $value || mb_trim($value) === '') {
            return;
        }

        $residenceFields = ['residence_number_1', 'residence_number_2', 'residence_number_3'];
        if (in_array($attribute, $residenceFields, true) && $this->duplicatesSiblingSlot($attribute, $value)) {
            $fail('The residence number fields cannot be duplicated.');
        }

        $query = DB::table('user_infos')->where(function ($q) use ($value, $residenceFields) {
            foreach ($residenceFields as $field) {
                $q->orWhere($field, $value);
            }
        });

        if ($this->currentUserId) {
            $query->where('user_id', '!=', $this->currentUserId);
        }

        if ($query->exists()) {
            $fail('The residence number has already been taken by another user.');
        }
    }

    private function duplicatesSiblingSlot(string $attribute, string $value): bool
    {
        $needle = ResidenceSlotResolver::normalizeNumber($value);
        if ($needle === null) {
            return false;
        }

        $requestData = $this->validator->getData();
        $siblings = array_filter(
            array_map(fn(int $slot) => "residence_number_{$slot}", range(1, ResidenceSlotResolver::SLOT_COUNT)),
            fn(string $field) => $field !== $attribute
        );
        $storedInfo =
            array_diff($siblings, array_keys($requestData)) === []
                ? null
                : $this->storedInfoIfValueChanged($attribute, $needle);

        foreach ($siblings as $sibling) {
            $siblingValue = array_key_exists($sibling, $requestData)
                ? $requestData[$sibling]
                : $storedInfo?->{$sibling};

            if (is_string($siblingValue) && ResidenceSlotResolver::normalizeNumber($siblingValue) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function storedInfoIfValueChanged(string $attribute, ?string $needle): ?UserInfo
    {
        if (!$this->currentUserId) {
            return null;
        }

        $info = UserInfo::query()->where('user_id', $this->currentUserId)->first();
        if (!$info || ResidenceSlotResolver::normalizeNumber($info->{$attribute}) === $needle) {
            return null;
        }

        return $info;
    }
}
