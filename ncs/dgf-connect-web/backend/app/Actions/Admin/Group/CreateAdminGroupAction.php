<?php

namespace App\Actions\Admin\Group;

use App\Actions\Admin\Group\Support\AdminGroupPayloadValidator;
use App\Enums\GroupKind;
use App\Models\Group;
use App\ValueObjects\GroupIdentity;

class CreateAdminGroupAction
{
    public function __construct(
        private readonly RecomputeGroupMembershipAction $recomputeGroupMembership,
        private readonly AdminGroupPayloadValidator $payloadValidator
    ) {}

    public function __invoke(array $attributes): Group
    {
        $validated = $this->payloadValidator->validateForStore($attributes);
        $kind = GroupKind::from($validated['kind']);
        $name = $validated['name'];

        $group = Group::query()->create([
            'kind' => $kind,
            'name' => $name,
            'predicate' => $kind === GroupKind::RuleBased ? $validated['predicate'] ?? null : null,
            'identity_key' => (new GroupIdentity(kind: $kind, name: $name))->key(),
        ]);

        if ($kind === GroupKind::RuleBased) {
            ($this->recomputeGroupMembership)($group);
        }

        return $group;
    }
}
