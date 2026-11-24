<?php

namespace App\Actions\Admin\Group\Strategies;

use App\Actions\Admin\Group\Contracts\MembershipStrategy;
use App\Enums\GroupKind;
use App\Enums\Prefecture;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

class RuleBasedStrategy implements MembershipStrategy
{
    public const ALLOWED_FIELDS = ['prefecture', 'japanese_level', 'gender', 'nationality'];

    public function kind(): GroupKind
    {
        return GroupKind::RuleBased;
    }

    public function groupsForUser(User $user, ?int $workplaceId = null): Collection
    {
        if ($user->is_disabled) {
            return new Collection();
        }

        $info = $user->info;
        if ($info === null) {
            return new Collection();
        }

        return Group::query()
            ->where('kind', GroupKind::RuleBased->value)
            ->whereNotNull('predicate')
            ->get()
            ->filter(fn(Group $group) => $this->infoMatchesPredicate($info->getAttributes(), $group->predicate ?? []))
            ->values();
    }

    public function userIdsForGroup(Group $group): Collection
    {
        if ($group->kind !== GroupKind::RuleBased) {
            return new Collection();
        }

        $predicate = $group->predicate ?? [];
        if (empty($predicate)) {
            return new Collection();
        }

        $query = User::query()
            ->where('users.is_disabled', false)
            ->join('user_infos', 'user_infos.user_id', '=', 'users.id');

        foreach ($predicate as $clause) {
            $field = $clause['field'] ?? null;
            $values = $clause['values'] ?? null;

            if (
                !in_array($field, self::ALLOWED_FIELDS, true) ||
                !is_array($values) ||
                empty($values) ||
                in_array('', $values, true)
            ) {
                return new Collection();
            }

            $expanded = $field === 'prefecture' ? Prefecture::expandValues($values) : $values;
            $query->whereIn('user_infos.' . $field, $expanded);
        }

        return $query->pluck('users.id');
    }

    public function userMatchesGroup(User $user, Group $group): bool
    {
        if ($user->is_disabled || $group->kind !== GroupKind::RuleBased || $user->info === null) {
            return false;
        }

        return $this->infoMatchesPredicate($user->info->getAttributes(), $group->predicate ?? []);
    }

    private function infoMatchesPredicate(array $info, array $predicate): bool
    {
        if (empty($predicate)) {
            return false;
        }

        foreach ($predicate as $clause) {
            $field = $clause['field'] ?? null;
            $values = $clause['values'] ?? null;

            if (
                !in_array($field, self::ALLOWED_FIELDS, true) ||
                !is_array($values) ||
                empty($values) ||
                in_array('', $values, true)
            ) {
                return false;
            }

            $actual = $info[$field] ?? null;
            if ($actual === null || $actual === '') {
                return false;
            }

            $actualNormalized = mb_strtolower(mb_trim((string) $actual));
            $expanded = $field === 'prefecture' ? Prefecture::expandValues($values) : $values;

            if (!in_array($actualNormalized, $expanded, false)) {
                return false;
            }
        }

        return true;
    }
}
