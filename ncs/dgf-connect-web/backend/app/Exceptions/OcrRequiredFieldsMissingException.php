<?php

namespace App\Exceptions;

use RuntimeException;

class OcrRequiredFieldsMissingException extends RuntimeException
{
    public const ERROR_CODE = 'OCR_REQUIRED_FIELDS_MISSING';

    /**
     * @param string[] $missingFields
     */
    public function __construct(public readonly array $missingFields)
    {
        parent::__construct(self::ERROR_CODE);
    }
}
