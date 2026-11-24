<?php

namespace App\Exceptions;

use RuntimeException;

class SystemGroupReadonlyException extends RuntimeException
{
    public function errors(): array
    {
        return [
            'group' => ['System groups are read-only.'],
        ];
    }
}
