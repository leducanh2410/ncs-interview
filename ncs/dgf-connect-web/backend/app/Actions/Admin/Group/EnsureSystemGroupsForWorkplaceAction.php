<?php

namespace App\Actions\Admin\Group;

use App\Enums\GroupKind;
use App\Enums\SourceTier;
use App\Models\Group;
use App\Models\Workplace;
use App\ValueObjects\GroupIdentity;

class EnsureSystemGroupsForWorkplaceAction
{
    /**
     * Ensure the four workplace-scoped system group rows exist:
     * one workplace_current and three workplace_seniority tiers.
     */
    public function __invoke(Workplace $workplace): void
    {
        Group::firstOrCreateByIdentity(
            new GroupIdentity(kind: GroupKind::WorkplaceCurrent, sourceWorkplaceId: $workplace->id)
        );

        foreach (SourceTier::seniorityTiers() as $tier) {
            Group::firstOrCreateByIdentity(
                new GroupIdentity(
                    kind: GroupKind::WorkplaceSeniority,
                    sourceWorkplaceId: $workplace->id,
                    sourceTier: $tier
                )
            );
        }
    }
}
