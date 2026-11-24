<?php

namespace App\Actions\Admin\DocumentReadConfig;

use App\Actions\ActionResult;
use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Support\Collection;

class GetDocumentReadConfigStatsAction
{
    public function __invoke(DocumentRequiredConfig $config): ActionResult
    {
        $eligibleUserIds = $this->resolveEligibleUserIds($config);
        $eligibleCount = $eligibleUserIds->count();

        $confirmedCount = DocumentReadConfirmation::query()
            ->where('document_required_config_id', $config->id)
            ->whereIn('user_id', $eligibleUserIds)
            ->count();

        $pendingCount = $eligibleCount - $confirmedCount;
        $confirmationRate = $eligibleCount === 0 ? 0.0 : (float) round($confirmedCount / $eligibleCount, 4);

        return new ActionResult(
            data: [
                'eligible_count' => $eligibleCount,
                'confirmed_count' => $confirmedCount,
                'pending_count' => $pendingCount,
                'confirmation_rate' => $confirmationRate,
            ]
        );
    }

    private function resolveEligibleUserIds(DocumentRequiredConfig $config): Collection
    {
        if ($config->workplace_id === null) {
            return User::query()->where('paper_status', 'approved')->pluck('id');
        }

        $workplaceUserIds = ShiftUser::query()
            ->whereNotNull('approved_at')
            ->whereHas('shift', function ($query) use ($config) {
                $query->where('workplace_id', $config->workplace_id);
            })
            ->pluck('user_id')
            ->unique()
            ->values();

        return User::query()->whereIn('id', $workplaceUserIds)->pluck('id');
    }
}
