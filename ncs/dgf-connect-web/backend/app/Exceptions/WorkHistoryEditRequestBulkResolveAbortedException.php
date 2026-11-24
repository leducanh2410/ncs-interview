<?php

namespace App\Exceptions;

use App\Actions\ActionResult;
use RuntimeException;

class WorkHistoryEditRequestBulkResolveAbortedException extends RuntimeException
{
    public function __construct(public readonly ActionResult $result)
    {
        parent::__construct('Work history edit request bulk resolve aborted');
    }
}
