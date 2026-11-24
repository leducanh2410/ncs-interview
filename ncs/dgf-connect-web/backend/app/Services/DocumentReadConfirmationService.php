<?php

namespace App\Services;

use App\Models\DocumentReadConfirmation;
use App\Models\DocumentRequiredConfig;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DocumentReadConfirmationService
{
    public function getPendingConfigs(User $user): Collection
    {
        if ($user->paper_status !== 'approved') {
            return collect();
        }

        $confirmedConfigIds = $user->documentReadConfirmations()->pluck('document_required_config_id');

        $configs = collect();

        $systemConfigs = DocumentRequiredConfig::query()
            ->with('document.attachments')
            ->active()
            ->system()
            ->whereNotIn('id', $confirmedConfigIds)
            ->get();
        $configs = $configs->merge($systemConfigs);

        $workplaceIds = ShiftUser::query()
            ->where('user_id', $user->id)
            ->whereNotNull('approved_at')
            ->with('shift')
            ->get()
            ->pluck('shift.workplace_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (!empty($workplaceIds)) {
            $workplaceConfigs = DocumentRequiredConfig::query()
                ->with('document.attachments')
                ->active()
                ->whereNotNull('workplace_id')
                ->whereIn('workplace_id', $workplaceIds)
                ->whereNotIn('id', $confirmedConfigIds)
                ->get();
            $configs = $configs->merge($workplaceConfigs);
        }

        return $configs->sortBy('id')->values();
    }

    public function setPendingFlag(User $user, bool $value): void
    {
        User::where('id', $user->id)->update(['has_pending_documents' => $value]);
    }

    public function evaluateAndResetFlag(User $user): void
    {
        $pendingConfigs = $this->getPendingConfigs($user);
        if ($pendingConfigs->isEmpty()) {
            $this->setPendingFlag($user, false);
        }
    }

    public function confirm(User $user, int $configId, string $ipAddress, array $deviceInfo): ?DocumentReadConfirmation
    {
        if ($user->paper_status !== 'approved') {
            return null;
        }

        $config = DocumentRequiredConfig::query()->find($configId);

        if (!$config || !$config->is_active) {
            return null;
        }

        if ($config->workplace_id !== null) {
            $hasApprovedShift = ShiftUser::query()
                ->where('user_id', $user->id)
                ->whereNotNull('approved_at')
                ->whereHas('shift', function ($query) use ($config) {
                    $query->where('workplace_id', $config->workplace_id);
                })
                ->exists();

            if (!$hasApprovedShift) {
                return null;
            }
        }

        return DB::transaction(function () use ($user, $configId, $ipAddress, $deviceInfo) {
            $confirmation = DocumentReadConfirmation::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'document_required_config_id' => $configId,
                ],
                [
                    'ip_address' => $ipAddress,
                    'device_info' => $deviceInfo,
                ]
            );

            $this->evaluateAndResetFlag($user);

            return $confirmation;
        });
    }

    public function serializePendingConfigs(Collection|EloquentCollection $pendingConfigs): array
    {
        return collect($pendingConfigs)
            ->each(fn($config) => $config->loadMissing('document.attachments'))
            ->map(
                fn($config) => [
                    'id' => $config->id,
                    'workplace_id' => $config->workplace_id,
                    'title_vi' => $config->title_vi,
                    'title_en' => $config->title_en,
                    'title_ja' => $config->title_ja,
                    'title_ne' => $config->title_ne ?? $config->title_en,
                    'title_si' => $config->title_si ?? $config->title_en,
                    'document' => $config->document,
                ]
            )
            ->values()
            ->toArray();
    }

    public function getPendingForLoginResponse(User $user): array
    {
        if (!$user->has_pending_documents) {
            return [];
        }

        $pendingConfigs = $this->getPendingConfigs($user);

        if ($pendingConfigs->isEmpty()) {
            $this->setPendingFlag($user, false);

            return [];
        }

        return $this->serializePendingConfigs($pendingConfigs);
    }
}
