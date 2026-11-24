<?php

namespace App\ModelFilters;

use App\Actions\Admin\User\ResolveCustomUserConfigsAction;
use App\Enums\UserInfoSearchField;
use App\Support\User\UserInfoNormalizer;

class UserFilter extends AbstractFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function id(string $id): UserFilter
    {
        return $this->where('id', $id);
    }

    public function ids(array $ids): UserFilter
    {
        return $this->whereIn('id', $ids);
    }

    public function staffNo(string $staffNo): UserFilter
    {
        return $this->whereLike('staff_no', $staffNo);
    }

    public function exactStaffNo(string $staffNo): UserFilter
    {
        return $this->where('staff_no', $staffNo);
    }

    public function staffNoEmpty(): UserFilter
    {
        return $this->whereNull('staff_no');
    }

    public function email(string $email): UserFilter
    {
        return $this->whereLike('email', $email);
    }

    public function name(string $name): UserFilter
    {
        return $this->where(function ($query) use ($name) {
            $query
                ->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$name}%"])
                ->orWhere('short_name', 'like', "%{$name}%");
        });
    }

    public function workplace(int $workplaceId): UserFilter
    {
        if ($workplaceId === self::ALL_VALUE_INT) {
            return $this;
        }

        return $this->whereHas('workplaces', function ($query) use ($workplaceId) {
            $query->where('workplace_id', $workplaceId);
        });
    }

    /**
     * Members of any of the given workplaces. Kept separate from
     * workplace() rather than overloading it, so no existing consumer of the
     * single-workplace filter changes behaviour.
     */
    public function workplaceIds(array $workplaceIds): UserFilter
    {
        // EloquentFilter never routes an empty array here (it strips empty
        // input before dispatching), so "restrict to no workplace" cannot be
        // expressed at this layer - MasterDataService::searchUser() and
        // UserService::listUsers() both answer that case with an empty result
        // instead of an unscoped search.
        if (empty($workplaceIds)) {
            return $this;
        }

        return $this->whereHas('workplaces', function ($query) use ($workplaceIds) {
            $query->whereIn('workplace_id', $workplaceIds);
        });
    }

    public function workplaceNone(): UserFilter
    {
        return $this->whereDoesntHave('workplaces');
    }

    public function isDisabled(string $isDisabled): UserFilter
    {
        if ($isDisabled === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('is_disabled', $isDisabled ? 1 : 0);
    }

    public function paperStatus(string $status): UserFilter
    {
        if ($status === self::ALL_VALUE) {
            return $this;
        }

        return $this->where('paper_status', $status);
    }

    public function role(string $roleName)
    {
        if ($roleName === self::ALL_VALUE) {
            return $this;
        }

        return $this->whereHas('roles', function ($query) use ($roleName) {
            $query->where('name', $roleName);
        });
    }

    public function faceRegistered(string $faceRegistered): UserFilter
    {
        if ($faceRegistered === self::ALL_VALUE) {
            return $this;
        }

        $currentProvider = config('services.face_recognition.provider');

        if ($faceRegistered === '1') {
            return $this->whereHas('userFaces', function ($query) use ($currentProvider) {
                $query->where('provider', $currentProvider);
            });
        }

        return $this->whereDoesntHave('userFaces', function ($query) use ($currentProvider) {
            $query->where('provider', $currentProvider);
        });
    }

    public function customConfig(string $customConfig): UserFilter
    {
        if ($customConfig !== '1') {
            return $this;
        }

        $resolveCustomConfigs = app(ResolveCustomUserConfigsAction::class);
        $defaults = $resolveCustomConfigs->defaults();

        return $this->whereHas('userConfig', function ($query) use ($resolveCustomConfigs, $defaults) {
            $resolveCustomConfigs->scopeDifferentFromDefaults($query, $defaults);
        });
    }

    public function groups(array $groupIds): UserFilter
    {
        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            $this->whereExists(function ($query) use ($groupId) {
                $query
                    ->selectRaw('1')
                    ->from('user_groups')
                    ->whereColumn('user_groups.user_id', 'users.id')
                    ->where('user_groups.group_id', $groupId);
            });
        }

        return $this;
    }

    public function query(string $query): UserFilter
    {
        return $this->where(function ($q) use ($query) {
            $query = "%{$query}%";
            $q->where('first_name', 'like', $query)
                ->orWhere('last_name', 'like', $query)
                ->orWhere('email', 'like', $query)
                ->orWhere('staff_no', 'like', $query);
        });
    }

    /**
     * One box over the name as the admin reads it on screen plus the identifiers.
     * Separate from query(): the admin list's search is a column-by-column match, and
     * a reconciliation admin types the full name off the row in front of them, which
     * never lives in one column.
     */
    public function keyword(string $keyword): UserFilter
    {
        return $this->where(function ($q) use ($keyword) {
            $like = "%{$keyword}%";
            $q->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$like])
                ->orWhere('last_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('short_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('staff_no', 'like', $like);
        });
    }

    public function permission(string|array $permission): UserFilter
    {
        $permissions = array_values(array_unique(array_filter((array) $permission)));

        foreach ($permissions as $permissionName) {
            $this->where(function ($query) use ($permissionName) {
                $query
                    ->whereHas('roles', function ($q) use ($permissionName) {
                        $q->whereHas('permissions', function ($p) use ($permissionName) {
                            $p->where('name', $permissionName);
                        });
                    })
                    ->orWhereHas('permissions', function ($q) use ($permissionName) {
                        $q->where('name', $permissionName);
                    });
            });
        }

        return $this;
    }

    public function infoSearchValue(string $value): UserFilter
    {
        $field = UserInfoSearchField::tryFrom((string) $this->input('info_search_field'));
        if (!$field || !$value) {
            return $this;
        }

        $value = $this->normalizeInfoSearchValue($field, $value);
        if ($value === '') {
            return $this;
        }

        $value = addcslashes($value, '%_\\');

        return $this->whereHas('info', function ($query) use ($field, $value) {
            if ($field === UserInfoSearchField::ResidenceNumber) {
                $query->where(function ($q) use ($value) {
                    $q->where('residence_number_1', 'like', "%{$value}%")
                        ->orWhere('residence_number_2', 'like', "%{$value}%")
                        ->orWhere('residence_number_3', 'like', "%{$value}%");
                });
            } else {
                $query->where($field->value, 'like', "%{$value}%");
            }
        });
    }

    private function normalizeInfoSearchValue(UserInfoSearchField $field, string $value): string
    {
        $normalizer = app(UserInfoNormalizer::class);

        return match ($field) {
            UserInfoSearchField::Address => (string) $normalizer->address($value),
            UserInfoSearchField::Prefecture => (string) $normalizer->prefecture($value),
            default => $value,
        };
    }
}
