<?php

namespace App\Actions\Admin;

use App\Actions\ActionResult;
use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Enums\PermissionName;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Database\Eloquent\Builder;

class GetMasterDataAction
{
    public function __construct(private readonly ShouldShowTestWorkplacesAction $shouldShowTestWorkplaces) {}

    /**
     * Get all master data including workplaces, roles, and workplace access
     *
     * @param User $user Authenticated user
     * @return ActionResult
     */
    public function __invoke(User $user): ActionResult
    {
        $showTestWorkplaces = ($this->shouldShowTestWorkplaces)($user);

        $workplaces = Workplace::query()
            ->select('id', 'name', 'is_test')
            ->when(!$showTestWorkplaces, fn(Builder $query) => $query->where('is_test', false))
            ->get();
        $roles = Role::all();
        $workplace_access = $user->getAccessibleWorkplacesByPermissionGroup([
            'shift',
            'work_history',
            'work_report',
            'check_in',
            'workplace',
            'fixed_schedule',
            'fixed_schedule_change_config',
            'fixed_schedule_change_request',
        ]);
        // Prefix group `shift` is any shift.* permission. Check-in search must
        // use this exact-permission list so managers with only shift.list at
        // another workplace do not see employees they cannot check in.
        $workplace_access['shift_check_in'] = $user->getWorkplaceIdsByPermission(PermissionName::ShiftCheckIn->value);

        return new ActionResult(data: compact('workplaces', 'roles', 'workplace_access'));
    }
}
