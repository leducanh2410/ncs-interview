<?php

namespace App\Services\User;

use App\Actions\Common\Exam\ApplyExamAudienceScopeAction;
use App\Models\Document;
use App\Models\Exam;
use App\Models\WorkplaceUser;
use Illuminate\Database\Eloquent\Builder;

class DocumentService
{
    public function __construct(private readonly ApplyExamAudienceScopeAction $applyExamAudienceScope) {}

    /**
     * Check if the user can access a specific document
     */
    public function canUserAccessDocument(int $userId, Document $document): bool
    {
        $userWorkplaceIds = WorkplaceUser::query()->where('user_id', $userId)->pluck('workplace_id');
        $documentWorkplaceIds = $document->workplaces()->pluck('workplace_id');
        if ($userWorkplaceIds->intersect($documentWorkplaceIds)->isNotEmpty()) {
            return true;
        }

        if ($document->users()->where('user_id', $userId)->exists()) {
            return true;
        }

        $now = now();

        return Exam::query()
            ->whereHas('documents', fn(Builder $query) => $query->where('documents.id', $document->id))
            ->tap(fn(Builder $query) => ($this->applyExamAudienceScope)($query, $userId, $userWorkplaceIds))
            ->where(fn(Builder $query) => $query->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(
                fn(Builder $query) => $query
                    ->where('is_required', true)
                    ->orWhereNull('end_at')
                    ->orWhere('end_at', '>=', $now)
            )
            ->exists();
    }
}
