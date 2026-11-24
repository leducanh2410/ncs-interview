<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Enums\RoleName;
use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use App\Models\User;
use Illuminate\Support\Arr;

class GetDocumentReadConfirmationsAction
{
    public function __invoke(
        DocumentRequiredConfig $config,
        array $filters,
        User $admin,
        ?int $perPage = null
    ): ActionResult {
        if (!$admin->hasRole(RoleName::GlobalAdmin->value)) {
            if ($config->workplace_id !== null) {
                $workplaceIds = $admin->getAccessibleWorkplacesByPermissionGroup(['workplace'])['workplace'] ?? [];
                if (!in_array($config->workplace_id, $workplaceIds, true)) {
                    abort(403, 'Access denied to this workplace');
                }
            }
        }

        $query = DocumentReadConfirmation::query()
            ->where('document_required_config_id', $config->id)
            ->with(['user', 'config']);

        if (Arr::has($filters, 'user_id') && Arr::get($filters, 'user_id') !== null) {
            $query->where('user_id', Arr::get($filters, 'user_id'));
        }

        $confirmations = $query->orderByDesc('created_at')->paginate($perPage);

        return new ActionResult(data: $confirmations);
    }
}
