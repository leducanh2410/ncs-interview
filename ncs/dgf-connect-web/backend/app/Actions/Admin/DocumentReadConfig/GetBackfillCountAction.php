<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use App\Models\ShiftUser;
use App\Models\User;

class GetBackfillCountAction
{
    public function __invoke(DocumentRequiredConfig $config): ActionResult
    {
        $confirmedUserIds = DocumentReadConfirmation::query()
            ->where('document_required_config_id', $config->id)
            ->pluck('user_id');

        if ($config->workplace_id === null) {
            $count = User::query()->where('paper_status', 'approved')->whereNotIn('id', $confirmedUserIds)->count();
        } else {
            $workplaceUserIds = ShiftUser::query()
                ->whereNotNull('approved_at')
                ->whereHas('shift', function ($query) use ($config) {
                    $query->where('workplace_id', $config->workplace_id);
                })
                ->pluck('user_id')
                ->unique();

            $count = User::query()->whereIn('id', $workplaceUserIds)->whereNotIn('id', $confirmedUserIds)->count();
        }

        return new ActionResult(data: ['affected_count' => $count]);
    }
}
