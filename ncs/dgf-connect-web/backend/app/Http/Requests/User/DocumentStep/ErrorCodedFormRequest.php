<?php

namespace App\Http\Requests\User\DocumentStep;

use App\Enums\ErrorCode;
use App\Exceptions\ErrorCodedValidationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class ErrorCodedFormRequest extends FormRequest
{
    abstract protected function errorCodeFor(string $attribute, string $rule, mixed $value): ErrorCode;

    abstract protected function errorCodeForCustomFailure(string $attribute): ErrorCode;

    protected function failedValidation(Validator $validator): void
    {
        $failedRules = $validator->failed();
        $errorCodes = [];

        foreach (array_keys($validator->errors()->toArray()) as $attribute) {
            $rule = array_key_first($failedRules[$attribute] ?? []);
            $errorCodes[$attribute] =
                $rule === null
                    ? $this->errorCodeForCustomFailure($attribute)
                    : $this->errorCodeFor($attribute, $rule, data_get($validator->getData(), $attribute));
        }

        throw new ErrorCodedValidationException($validator, $errorCodes);
    }
}
