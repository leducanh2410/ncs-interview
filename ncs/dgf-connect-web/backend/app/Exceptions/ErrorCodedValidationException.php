<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ErrorCodedValidationException extends ValidationException
{
    /**
     * @param array<string, ErrorCode> $errorCodes
     */
    public function __construct(Validator $validator, private readonly array $errorCodes)
    {
        parent::__construct($validator);
    }

    public function render(): JsonResponse
    {
        $messages = $this->validator->errors()->toArray();
        $firstAttribute = array_key_first($messages);

        return ResponseHelper::error($this->errorCodes[$firstAttribute], $this->status, [
            'message' => $messages[$firstAttribute][0],
            'errors' => $messages,
            'error_codes' => array_map(fn(ErrorCode $code) => $code->value, $this->errorCodes),
        ]);
    }
}
