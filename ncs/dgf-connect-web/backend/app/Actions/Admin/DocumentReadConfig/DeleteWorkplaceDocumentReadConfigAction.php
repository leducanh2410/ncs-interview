<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentRequiredConfig;
use App\Models\Workplace;

class DeleteWorkplaceDocumentReadConfigAction
{
    public function __invoke(DocumentRequiredConfig $config, Workplace $workplace): ActionResult
    {
        if ($config->workplace_id !== $workplace->id) {
            abort(403, 'Config does not belong to this workplace');
        }

        $config->delete();

        return new ActionResult(data: ['success' => true]);
    }
}
