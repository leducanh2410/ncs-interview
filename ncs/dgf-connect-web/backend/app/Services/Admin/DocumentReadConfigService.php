<?php

namespace App\Services\Admin;

use App\Enums\RoleName;
use App\Jobs\BackfillDocumentPendingFlagsJob;
use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DocumentReadConfigService
{
    public function listSystemConfigs(): Collection
    {
        return DocumentRequiredConfig::query()
            ->with(['document', 'workplace', 'createdBy'])
            ->system()
            ->orderBy('id')
            ->get();
    }

    public function listWorkplaceConfigs(Workplace $workplace): Collection
    {
        return DocumentRequiredConfig::query()
            ->with(['document', 'workplace', 'createdBy'])
            ->where('workplace_id', $workplace->id)
            ->orderBy('id')
            ->get();
    }

    public function listAllConfigs(User $admin): Collection
    {
        $configs = DocumentRequiredConfig::query()
            ->with(['document', 'workplace', 'createdBy'])
            ->orderBy('id');

        if (!$admin->hasRole(RoleName::GlobalAdmin->value)) {
            $workplaceIds = $admin->getAccessibleWorkplacesByPermissionGroup(['workplace'])['workplace'] ?? [];
            if (!empty($workplaceIds)) {
                $configs->where(function ($query) use ($workplaceIds) {
                    $query->whereNull('workplace_id')->orWhereIn('workplace_id', $workplaceIds);
                });
            } else {
                $configs->whereNull('workplace_id');
            }
        }

        return $configs->get();
    }

    public function createConfig(array $data, User $createdBy): DocumentRequiredConfig
    {
        return DocumentRequiredConfig::query()->create([
            'document_id' => $data['document_id'] ?? null,
            'workplace_id' => $data['workplace_id'] ?? null,
            'title_vi' => $data['title_vi'],
            'title_en' => $data['title_en'],
            'title_ja' => $data['title_ja'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $createdBy->id,
        ]);
    }

    public function updateConfig(DocumentRequiredConfig $config, array $data): DocumentRequiredConfig
    {
        if (isset($data['document_id']) && $data['document_id'] !== $config->document_id) {
            $hasConfirmations = DocumentReadConfirmation::query()
                ->where('document_required_config_id', $config->id)
                ->exists();

            if ($hasConfirmations) {
                throw ValidationException::withMessages([
                    'document_id' => ['Cannot change document_id when confirmations exist for this config'],
                ]);
            }
        }

        $updateData = array_filter(
            [
                'document_id' => $data['document_id'] ?? null,
                'title_vi' => $data['title_vi'] ?? null,
                'title_en' => $data['title_en'] ?? null,
                'title_ja' => $data['title_ja'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : null,
            ],
            fn($value) => $value !== null
        );

        if (!empty($updateData)) {
            $config->update($updateData);
        }

        return $config->fresh();
    }

    public function deleteConfig(DocumentRequiredConfig $config): void
    {
        $config->delete();
    }

    public function getBackfillCount(DocumentRequiredConfig $config): int
    {
        $confirmedUserIds = DocumentReadConfirmation::query()
            ->where('document_required_config_id', $config->id)
            ->pluck('user_id');

        if ($config->workplace_id === null) {
            return User::query()->where('paper_status', 'approved')->whereNotIn('id', $confirmedUserIds)->count();
        }

        $workplaceUserIds = ShiftUser::query()
            ->whereNotNull('approved_at')
            ->whereHas('shift', function ($query) use ($config) {
                $query->where('workplace_id', $config->workplace_id);
            })
            ->pluck('user_id')
            ->unique();

        return User::query()->whereIn('id', $workplaceUserIds)->whereNotIn('id', $confirmedUserIds)->count();
    }

    public function triggerBackfill(DocumentRequiredConfig $config): void
    {
        if (!$config->is_active) {
            throw ValidationException::withMessages([
                'config' => ['Cannot trigger backfill for inactive config'],
            ]);
        }

        BackfillDocumentPendingFlagsJob::dispatch($config->id);
    }
}
