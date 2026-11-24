<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Enums\RoleName;
use App\Models\DocumentRequiredConfig;
use App\Models\User;

class GetDocumentReadConfigAction
{
    public function __invoke(DocumentRequiredConfig $config, User $admin): ActionResult
    {
        if (!$admin->hasRole(RoleName::GlobalAdmin->value) && $config->workplace_id !== null) {
            $workplaceIds = $admin->getAccessibleWorkplacesByPermissionGroup(['workplace'])['workplace'] ?? [];
            if (!in_array($config->workplace_id, $workplaceIds, true)) {
                abort(403, 'Access denied to this workplace');
            }
        }

        $config->load(['document', 'workplace', 'createdBy']);

        return new ActionResult(data: $config);
    }
}
