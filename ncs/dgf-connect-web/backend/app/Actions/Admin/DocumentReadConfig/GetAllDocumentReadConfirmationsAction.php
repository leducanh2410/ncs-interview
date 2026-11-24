<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Enums\RoleName;
use App\Models\DocumentReadConfirmation;
use App\Models\User;

class GetAllDocumentReadConfirmationsAction
{
    public function __invoke(array $filters, User $admin, ?int $perPage = null): ActionResult
    {
        $query = DocumentReadConfirmation::query()->with(['user', 'config.workplace']);

        if (!$admin->hasRole(RoleName::GlobalAdmin->value)) {
            $workplaceIds = $admin->getAccessibleWorkplacesByPermissionGroup(['workplace'])['workplace'] ?? [];
            $query->whereHas('config', function ($configQuery) use ($workplaceIds) {
                $configQuery->whereNull('workplace_id');

                if (!empty($workplaceIds)) {
                    $configQuery->orWhereIn('workplace_id', $workplaceIds);
                }
            });
        }

        $confirmations = $query->filter($filters)->orderByDesc('created_at')->paginateFilter($perPage);

        return new ActionResult(data: $confirmations);
    }
}
