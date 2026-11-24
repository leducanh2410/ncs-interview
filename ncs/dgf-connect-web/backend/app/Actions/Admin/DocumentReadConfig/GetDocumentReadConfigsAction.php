<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Enums\RoleName;
use App\Models\DocumentRequiredConfig;
use App\Models\User;

class GetDocumentReadConfigsAction
{
    public function __invoke(array $filters, User $admin, ?int $perPage = null): ActionResult
    {
        $query = DocumentRequiredConfig::query()
            ->with(['document', 'workplace', 'createdBy'])
            ->orderBy('id');

        if (!$admin->hasRole(RoleName::GlobalAdmin->value)) {
            $workplaceIds = $admin->getAccessibleWorkplacesByPermissionGroup(['workplace'])['workplace'] ?? [];
            if (!empty($workplaceIds)) {
                $query->where(function ($q) use ($workplaceIds) {
                    $q->whereNull('workplace_id')->orWhereIn('workplace_id', $workplaceIds);
                });
            } else {
                $query->whereNull('workplace_id');
            }
        }

        $configs = $query->filter($filters)->paginateFilter($perPage);

        return new ActionResult(data: $configs);
    }
}
