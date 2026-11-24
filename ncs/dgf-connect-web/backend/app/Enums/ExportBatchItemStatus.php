<?php

namespace App\Enums;

enum ExportBatchItemStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
