<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentRequiredConfig;
use App\Models\User;
use App\Models\Workplace;

class GetWorkplaceDocumentReadConfigsAction
{
    public function __invoke(Workplace $workplace, User $admin): ActionResult
    {
        $configs = DocumentRequiredConfig::query()
            ->with(['document', 'workplace', 'createdBy'])
            ->where('workplace_id', $workplace->id)
            ->orderBy('id')
            ->get();

        return new ActionResult(data: $configs);
    }
}
