<?php

namespace App\Exceptions;

use App\Actions\ActionResult;
use RuntimeException;

class FixedScheduleBulkResolveAbortedException extends RuntimeException
{
    public function __construct(public readonly ActionResult $result)
    {
        parent::__construct('Fixed schedule bulk resolve aborted');
    }
}
