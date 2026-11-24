<?php

namespace App\Exceptions;

use RuntimeException;

class OcrResultInvalidatedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('OCR result was invalidated while processing');
    }
}
