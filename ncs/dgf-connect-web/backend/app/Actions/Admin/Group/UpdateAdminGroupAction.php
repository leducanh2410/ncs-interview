<?php

namespace App\Actions\Admin\Group;

use App\Actions\Admin\Group\Support\AdminGroupPayloadValidator;
use App\Enums\GroupKind;
use App\Exceptions\SystemGroupReadonlyException;
use App\Models\Group;
use App\ValueObjects\GroupIdentity;
use Illuminate\Validation\ValidationException;

class UpdateAdminGroupAction
{
    public function __construct(
        private readonly RecomputeGroupMembershipAction $recomputeGroupMembership,
        private readonly AdminGroupPayloadValidator $payloadValidator
    ) {}

    public function __invoke(Group $group, array $attributes): Group
    {
        if ($group->isSystem()) {
            throw new SystemGroupReadonlyException();
        }

        $validated = $this->payloadValidator->validateForUpdate($group, $attributes);
        $shouldRecompute = false;

        if (array_key_exists('name', $validated)) {
            $group->name = $validated['name'];
            $group->identity_key = (new GroupIdentity(kind: $group->kind, name: $validated['name']))->key();
        }

        if (array_key_exists('predicate', $validated)) {
            if ($group->kind !== GroupKind::RuleBased) {
                throw ValidationException::withMessages([
                    'predicate' => ['Only rule-based groups can define predicates.'],
                ]);
            }

            $group->predicate = $validated['predicate'];
            $shouldRecompute = true;
        }

        $group->save();

        if ($shouldRecompute) {
            ($this->recomputeGroupMembership)($group);
        }

        return $group->fresh();
    }
}
