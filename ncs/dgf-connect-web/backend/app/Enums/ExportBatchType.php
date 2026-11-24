<?php

namespace App\Enums;

enum ExportBatchType: string
{
    case Pdf = 'pdf';
    case Csv = 'csv';
    case WorkHistory = 'work_history';
}
