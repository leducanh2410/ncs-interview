<?php

namespace App\Actions\Admin\PdfExport;

use App\Actions\ActionResult;
use App\Models\ExportBatch;

class GetBatchItemsAction
{
    public function __invoke(ExportBatch $batch, int $pageSize = 15): ActionResult
    {
        $items = $batch
            ->items()
            ->with('user:id,first_name,last_name,short_name,staff_no,profile_picture')
            ->orderBy('id')
            ->paginate($pageSize);

        return new ActionResult(data: $items);
    }
}
