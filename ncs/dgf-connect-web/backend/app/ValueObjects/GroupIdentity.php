<?php

namespace App\ValueObjects;

use App\Enums\GroupKind;
use App\Enums\SourceTier;

readonly class GroupIdentity
{
    public function __construct(
        public GroupKind $kind,
        public ?int $sourceWorkplaceId = null,
        public ?string $sourcePrefecture = null,
        public ?SourceTier $sourceTier = null,
        public ?string $name = null
    ) {}

    public function key(): string
    {
        $parts = [
            $this->kind->value,
            $this->sourceWorkplaceId === null ? 'null' : (string) $this->sourceWorkplaceId,
            $this->sourcePrefecture ?? 'null',
            $this->sourceTier?->value ?? 'null',
        ];

        $key = implode('|', $parts);

        if ($this->name !== null && ($this->kind === GroupKind::Manual || $this->kind === GroupKind::RuleBased)) {
            $key .= '|' . $this->name;
        }

        return $key;
    }

    public function toAttributes(): array
    {
        return [
            'kind' => $this->kind,
            'source_workplace_id' => $this->sourceWorkplaceId,
            'source_prefecture' => $this->sourcePrefecture,
            'source_tier' => $this->sourceTier,
            'name' => $this->name,
            'identity_key' => $this->key(),
        ];
    }
}
