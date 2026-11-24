<?php

namespace App\Actions\Admin\Group;

use App\Enums\GroupKind;
use App\Enums\SourceTier;
use App\Models\Group;
use Illuminate\Support\Facades\Lang;

class BuildGroupDisplayName
{
    private const LOCALES = ['en', 'ja', 'vi'];

    /**
     * Compose the display_name map for a group. For manual / rule_based the
     * user-typed name is mirrored across every locale; for system kinds the
     * label is rendered from lang/{locale}/groups.php.
     *
     * @return array{en:string, ja:string, vi:string}
     */
    public function __invoke(Group $group): array
    {
        if ($group->kind === GroupKind::Manual || $group->kind === GroupKind::RuleBased) {
            $name = $group->name ?? '';

            return ['en' => $name, 'ja' => $name, 'vi' => $name];
        }

        $workplaceName = $group->relationLoaded('workplace')
            ? $group->workplace?->name ?? ''
            : $group->workplace()->value('name') ?? '';

        return $this->renderSystem($group->kind, $group->source_tier, $workplaceName);
    }

    /**
     * @return array{en:string, ja:string, vi:string}
     */
    public function fromAttributes(
        GroupKind $kind,
        ?SourceTier $tier,
        ?string $workplaceName,
        ?string $name = null
    ): array {
        if ($kind === GroupKind::Manual || $kind === GroupKind::RuleBased) {
            $value = $name ?? '';

            return ['en' => $value, 'ja' => $value, 'vi' => $value];
        }

        return $this->renderSystem($kind, $tier, $workplaceName ?? '');
    }

    /**
     * @return array{en:string, ja:string, vi:string}
     */
    private function renderSystem(GroupKind $kind, ?SourceTier $tier, string $workplaceName): array
    {
        $result = [];
        foreach (self::LOCALES as $locale) {
            $result[$locale] = $this->renderForLocale($kind, $tier, $workplaceName, $locale);
        }

        return $result;
    }

    private function renderForLocale(GroupKind $kind, ?SourceTier $tier, string $workplaceName, string $locale): string
    {
        $key = match ($kind) {
            GroupKind::WorkplaceCurrent => 'groups.workplace_current',
            GroupKind::Worktime => 'groups.worktime.' . ($tier?->value ?? ''),
            GroupKind::WorkplaceSeniority => 'groups.workplace_seniority.' . ($tier?->value ?? ''),
            GroupKind::FrequentUrgentWorker => 'groups.frequent_urgent_worker',
            default => '',
        };

        if ($key === '' || str_ends_with($key, '.')) {
            return '';
        }

        return Lang::get($key, ['workplace' => $workplaceName], $locale);
    }
}
