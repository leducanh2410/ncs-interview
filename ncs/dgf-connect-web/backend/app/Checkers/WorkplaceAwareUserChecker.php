<?php

namespace App\Checkers;

use BackedEnum;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laratrust\Checkers\User\UserDefaultChecker;
use Laratrust\Helper;
use Laratrust\Models\Team;

/**
 * Custom user permission checker that treats team_id = null as global access.
 *
 * This checker extends Laratrust's default behavior to interpret null team_id
 * as "access to all teams/workplaces" when checking permissions. This aligns
 * with the semantic that a role/permission without team restriction should
 * grant access to all teams.
 *
 * Background:
 * Laratrust's default isInSameTeam() method performs exact matching:
 * - null == 5 returns false
 * - This means global permissions (team_id = null) don't match specific teams
 *
 * Our override:
 * - team_id = null -> grants access to ALL teams (global access)
 * - team_id = 5 -> grants access ONLY to team 5 (scoped access)
 *
 * Usage:
 * Configure in config/laratrust.php:
 * 'checkers' => ['user' => \App\Checkers\WorkplaceAwareUserChecker::class]
 *
 * Then use standard Laratrust methods:
 * $user->isAbleTo('shift.create', 5) // Returns true if user has global or workplace 5 access
 */
class WorkplaceAwareUserChecker extends UserDefaultChecker
{
    /**
     * Check if user has a permission by its name.
     *
     * This method overrides Laratrust's default implementation to treat
     * team_id = null as global access. When a user has a permission/role
     * with team_id = null, they can access that permission for any team.
     *
     * @param string|array|BackedEnum $permission Permission name(s) to check
     * @param mixed $team Team ID to check permission for (null = no team filter with strict_check=false)
     * @param bool $requireAll If checking multiple permissions, require all (true) or any (false)
     * @return bool True if user has the permission(s)
     */
    public function currentUserHasPermission(
        string|array|BackedEnum $permission,
        mixed $team = null,
        bool $requireAll = false
    ): bool {
        $permission = Helper::standardize($permission);
        [
            'team' => $team,
            'require_all' => $requireAll,
        ] = $this->getRealValues($team, $requireAll, 'is_bool');

        // Handle array of permissions recursively
        if (is_array($permission)) {
            if (empty($permission)) {
                return true;
            }

            foreach ($permission as $permissionName) {
                $hasPermission = $this->currentUserHasPermission($permissionName, $team);

                if ($hasPermission && !$requireAll) {
                    return true;
                }

                if (!$hasPermission && $requireAll) {
                    return false;
                }
            }

            // If we've made it this far and $requireAll is FALSE, then NONE of the perms were found.
            // If we've made it this far and $requireAll is TRUE, then ALL of the perms were found.
            // Return the value of $requireAll.
            return $requireAll;
        }

        $teamId = Helper::getIdFor($team, 'team');

        // STEP 1: Check direct permissions assigned to user
        // This section checks if the user has the permission directly attached to their account.
        foreach ($this->userCachedPermissions() as $perm) {
            if (Str::is($permission, $perm['name'])) {
                // KEY OVERRIDE: Global permission (team_id = null) grants access to all teams
                // This is the core change from Laratrust's default behavior.
                // If a permission is assigned with team_id = null, it grants access to ALL teams,
                // not just when checking without specifying a team.
                if ($perm['pivot'][Team::modelForeignKey()] === null) {
                    return true;
                }

                // Check if permission is scoped to the specified team
                // Standard Laratrust behavior: team_id must exactly match
                if ($this->isInSameTeam($perm, $teamId)) {
                    return true;
                }
            }
        }

        // STEP 2: Check permissions through roles
        // If user doesn't have direct permission, check if any of their roles grant the permission.
        foreach ($this->userCachedRoles() as $role) {
            // KEY OVERRIDE: Global role (team_id = null) grants access to all teams
            // If a user has a role assigned globally (team_id = null), that role's permissions
            // apply to ALL teams. This allows creating "super roles" that work across all workplaces.
            if ($role['pivot'][Team::modelForeignKey()] === null) {
                // User has this role globally, check if role has the permission
                $roleModel = $this->hidrateRole(Config::get('laratrust.models.role'), $role);
                if ($roleModel->hasPermission($permission)) {
                    return true;
                }
            } elseif ($this->isInSameTeam($role, $teamId)) {
                // User has this role scoped to the specified team, check if role has the permission
                // Standard Laratrust behavior: role must be assigned to the exact team being checked
                $roleModel = $this->hidrateRole(Config::get('laratrust.models.role'), $role);
                if ($roleModel->hasPermission($permission)) {
                    return true;
                }
            }
        }

        // User does not have the permission for this team
        return false;
    }

    /**
     * Check if role/permission is in the same team.
     *
     * This is a local implementation of Laratrust's private isInSameTeam() method.
     *
     * @param array $rolePermission The role or permission array with pivot data
     * @param int|string|null $teamId The team ID to check against
     * @return bool True if in the same team or teams are disabled
     */
    private function isInSameTeam(array $rolePermission, int|string|null $teamId = null): bool
    {
        if (!Config::get('laratrust.teams.enabled') || (!Config::get('laratrust.teams.strict_check') && !$teamId)) {
            return true;
        }

        return $rolePermission['pivot'][Team::modelForeignKey()] == $teamId;
    }

    /**
     * Hydrate a role model from cached data.
     *
     * This is a local implementation of Laratrust's private hidrateRole() method.
     *
     * @param string $class The role class name
     * @param array $data The role data from cache
     * @return mixed The hydrated role model
     */
    private function hidrateRole(string $class, array $data): mixed
    {
        if (!isset($data['pivot'])) {
            throw new \Exception("The 'pivot' attribute in the {$class} is hidden");
        }

        $role = new $class();
        $primaryKey = $role->getKeyName();

        $role
            ->setAttribute($primaryKey, $data[$primaryKey])
            ->setAttribute('name', $data['name'])
            ->setRelation(
                'pivot',
                \Illuminate\Database\Eloquent\Relations\MorphPivot::fromRawAttributes(
                    $role,
                    $data['pivot'],
                    'pivot_table'
                )
            );

        return $role;
    }
}
