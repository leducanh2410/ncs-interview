<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentRequiredConfig;

class DeleteDocumentReadConfigAction
{
    public function __invoke(DocumentRequiredConfig $config): ActionResult
    {
        $config->delete();

        return new ActionResult(data: ['success' => true]);
    }
}
