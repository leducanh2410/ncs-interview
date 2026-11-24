<?php

namespace App\Actions\Admin\User;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whether an account holds a role that signs in to the admin web and the admin app.
 *
 * The document flags on the employee screens describe a file an employee has to keep
 * complete for their residence status. An account that signs in to the admin is not working
 * under that obligation, so a mismatch or a gap left over from before it was given admin
 * access says nothing an admin can act on — a manager promoted from the floor keeps their
 * old paper row, which is why the badges had to be silenced by who the account is rather
 * than by whether a paper exists.
 *
 * "Admin" here is the question both admin clients ask at login — `manager.auth`, which is
 * also the permission that routes a push to the admin project rather than the employee app
 * — so a custom role granted admin access is covered without naming it anywhere. Only roles
 * answer it: a direct permission_user grant is deliberately ignored, the same rule
 * User::getWorkplaceIdsByPermission() draws, because production assigns admin access
 * through roles and nothing else.
 *
 * The line is drawn at admin access and nothing narrower, so a role that carries
 * `manager.auth` **and** still works shifts — a `leader` granted `manager.attendance.check_in`,
 * clocking in through the admin attendance screens — is out of scope for document flags too.
 * That is deliberate rather than overlooked: AssignRolesAction::validateExclusiveUserRole()
 * forbids holding `user` alongside any other role, so an account carrying an admin role has
 * already stopped being classified as an employee, and the alternative — exempting only
 * accounts with no residence status or no paper row — would miss the promoted employee this
 * whole rule exists for, since that account keeps both. If the company ever needs a
 * shift-working manager's residence documents tracked, this is the place to split the two
 * questions apart.
 *
 * What this does **not** cover, on purpose: the expiry alerts on the detail screen's
 * Information tab (`student_card_expiry_alert`, `residence_card_expiry_alert`,
 * `passport_expiry_alert`). They answer a different question. A badge says the file owes
 * the company a document and offers a button to demand it; an expiry date says a document
 * someone actually holds runs out on a given day, which stays true of a manager promoted
 * from the floor and of any foreign national on the payroll. The same three attributes
 * feed the shift planning screen (GetShiftPlanningAction), where a `leader` still working
 * shifts is exactly who they are drawn for, so silencing them by role would take a useful
 * warning away from the screen that needs it most. Same reasoning keeps
 * `document_mismatches` intact in UserService::getPapers() — a fact about the documents,
 * not a demand on the account.
 *
 * @see \App\Http\Controllers\AuthController::validateLogin()
 * @see \App\Jobs\PushNotificationToUser
 */
class ResolveWebAdminAccessAction
{
    public function __invoke(User $user): bool
    {
        return $user->roles()->whereHas('permissions', $this->managerAuth())->exists();
    }

    /**
     * The same answer for a page of users, in one query.
     *
     * @param Collection<int, User> $users
     * @return array<int, true> keyed by user id, for isset() lookup
     */
    public function forUsers(Collection $users): array
    {
        $ids = $users->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $adminIds = User::query()
            ->whereIn('id', $ids)
            ->whereHas('roles.permissions', $this->managerAuth())
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();

        return array_fill_keys($adminIds, true);
    }

    private function managerAuth(): callable
    {
        return static fn(Builder $query) => $query->where('name', PermissionName::ManagerAuth->value);
    }
}
