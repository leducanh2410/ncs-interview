<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Models\Traits\FilterQuery;
use App\Models\Traits\NormalizesDiacritics;
use App\Notifications\ResetPasswordNotification;
use App\Support\Residence\ResidenceSlotResolver;
use Carbon\Carbon;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;

/**
 * @property int $id
 * @property bool $is_disabled
 * @property bool $is_test
 * @property string $first_name
 * @property string $last_name
 * @property string $first_name_kana
 * @property string $last_name_kana
 * @property string $email
 * @property ?string $staff_no
 * @property string $paper_status
 * @property string $profile_picture
 * @property string $remember_token
 * @property ?string $tfa_secret
 * @property bool $has_pending_documents
 * @property bool $has_pending_votes
 */
class User extends Authenticatable implements LaratrustUser
{
    use Filterable, FilterQuery;
    use HasFactory, Notifiable;
    use HasRolesAndPermissions;
    use NormalizesDiacritics;

    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token', 'tfa_secret', 'roles', 'created_at', 'updated_at', 'short_name'];

    protected $appends = ['name', 'name_kana'];

    public function setFirstNameAttribute(?string $value): void
    {
        $normalized = $this->removeDiacritics($value);
        $this->attributes['first_name'] = $normalized !== null ? mb_strtoupper($normalized) : null;
    }

    public function setLastNameAttribute(?string $value): void
    {
        $normalized = $this->removeDiacritics($value);
        $this->attributes['last_name'] = $normalized !== null ? mb_strtoupper($normalized) : null;
    }

    public function setFirstNameKanaAttribute(?string $value): void
    {
        $normalized = $this->removeDiacritics($value);
        $this->attributes['first_name_kana'] = $normalized !== null ? mb_strtoupper($normalized) : null;
    }

    public function setLastNameKanaAttribute(?string $value): void
    {
        $normalized = $this->removeDiacritics($value);
        $this->attributes['last_name_kana'] = $normalized !== null ? mb_strtoupper($normalized) : null;
    }

    public function getNameAttribute(): string
    {
        return $this->last_name . ' ' . $this->first_name;
    }

    /**
     * Display-friendly name: collapses the long Sri Lankan ge-name/house-name
     * form to the person's two given names. Persisted to the `short_name`
     * column at creation time and kept in sync by UserInfoShortNameObserver
     * whenever nationality changes, so this never touches the `info`
     * relation at read time. Null for non-Sri-Lankan users and for users
     * created before this column existed (no backfill), in which case this
     * falls back to the full name.
     */
    public function getShortNameAttribute(): string
    {
        return $this->attributes['short_name'] ?? $this->name;
    }

    public function getNameKanaAttribute(): string
    {
        return $this->last_name_kana . ' ' . $this->first_name_kana;
    }

    /**
     * `name` is overridden to `short_name` at the serialize boundary so every
     * JSON response carries the Sri Lanka short-name display without callers
     * having to opt in. Falls back to the full name when `short_name` wasn't
     * selected (partial `select()`) or is null (non-Sri-Lankan users, and
     * users created before this column existed).
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        $shortName = $this->attributes['short_name'] ?? null;
        if ($shortName !== null && isset($array['name'])) {
            $array['name'] = $shortName;
        }

        return $array;
    }

    public function getStudentCardExpiryAlertAttribute(): bool
    {
        if (!$this->info || !$this->info->student_card_expiry_date) {
            return false;
        }

        $minAlertDate = Carbon::now()->addDays(config('work.document_expiry_alert'));

        return Carbon::parse($this->info->student_card_expiry_date)->lt($minAlertDate);
    }

    public function getResidenceCardExpiryAlertAttribute(): bool
    {
        $slot = ResidenceSlotResolver::latestSlot($this->info);
        $residenceExpiryDate = $slot !== null ? $this->info->{"residence_expiry_date_{$slot}"} : null;
        if (!$residenceExpiryDate) {
            return false;
        }
        $minAlertDate = Carbon::now()->addDays(config('work.document_expiry_alert'));

        return Carbon::parse($residenceExpiryDate)->lt($minAlertDate);
    }

    public function getPassportExpiryAlertAttribute(): bool
    {
        if (!$this->info || !$this->info->passport_expiry_date) {
            return false;
        }

        $minAlertDate = Carbon::now()->addDays(config('work.document_expiry_alert'));

        return Carbon::parse($this->info->passport_expiry_date)->lt($minAlertDate);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(AccessToken::class);
    }

    public function userFaces(): HasMany
    {
        return $this->hasMany(UserFace::class);
    }

    public function getFaceRegisteredAttribute(): bool
    {
        $currentProvider = config('services.face_recognition.provider');

        return $this->userFaces()->where('provider', $currentProvider)->exists();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function info(): HasOne
    {
        return $this->hasOne(UserInfo::class, 'user_id');
    }

    public function userConfig(): HasOne
    {
        return $this->hasOne(UserConfig::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(UserImage::class);
    }

    public function paper(): HasOne
    {
        return $this->hasOne(UserPaper::class);
    }

    public function workplaces(): BelongsToMany
    {
        return $this->belongsToMany(Workplace::class, 'workplace_users')->using(WorkplaceUser::class)->withTimestamps();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'user_groups')->using(UserGroup::class)->withTimestamps();
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function documentReadConfirmations(): HasMany
    {
        return $this->hasMany(DocumentReadConfirmation::class);
    }

    public function examResults(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function pollResponses(): HasMany
    {
        return $this->hasMany(PollResponse::class);
    }

    public function pollTargets(): BelongsToMany
    {
        return $this->belongsToMany(Poll::class, 'poll_targets');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(UserBankAccount::class);
    }

    public function effectiveBankAccount(): HasOne
    {
        return $this->hasOne(UserBankAccount::class)->ofMany(['id' => 'max'], fn($query) => $query->effective());
    }

    public function getRolesSimple(): Collection
    {
        return $this->roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'team_id' => $role->pivot->team_id,
            ];
        });
    }

    /**
     * Get workplace IDs where the user has the given permission via roles.
     *
     * Role-only by design (role_user.team_id): this is the list/filter source
     * used by applyWorkplaceFilter() across the app. Direct permission_user
     * grants are ignored here — production never assigns those (givePermission
     * is test-only). Write-path checks use canAccessWorkplace()/isAbleTo()
     * which do honour permission_user, matching route middleware.
     *
     * @param string $permission Permission name (e.g., 'shift.create')
     * @return array|null Returns null for global access, empty array for no access, or array of workplace IDs
     */
    public function getWorkplaceIdsByPermission(string $permission): ?array
    {
        // GlobalAdmin has access to all workplaces
        if ($this->hasRole(RoleName::GlobalAdmin->value)) {
            return null;
        }

        // Get all team_ids for roles that have this permission
        $teamIds = $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->get()
            ->pluck('pivot.team_id');

        // If any team_id is NULL, user has global access
        if ($teamIds->contains(null)) {
            return null;
        }

        // Filter out nulls, get unique workplace IDs
        return $teamIds->filter()->unique()->values()->toArray();
    }

    /**
     * Check if user can access all workplaces for a given permission
     *
     * @param string $permission Permission name
     * @return bool
     */
    public function canAccessAllWorkplaces(string $permission): bool
    {
        return $this->getWorkplaceIdsByPermission($permission) === null;
    }

    /**
     * True when the actor is not limited to a workplace list: GlobalAdmin, a
     * role with team_id = null, or a direct permission_user grant with
     * team_id = null. Used when there is no workplace to hand to isAbleTo()
     * (e.g. a missing shift_user).
     */
    public function hasUnrestrictedWorkplaceAccess(string $permission): bool
    {
        if ($this->canAccessAllWorkplaces($permission)) {
            return true;
        }

        return $this->permissions()
            ->where('permissions.name', $permission)
            ->get()
            ->contains(fn($perm) => $perm->pivot->team_id === null);
    }

    /**
     * Whether the user may act on the given workplace for this permission.
     *
     * Global access (GlobalAdmin, or a role with team_id = null) is allowed
     * for any workplace so this matches GET-side `getWorkplaceIdsByPermission()`.
     * Scoped access is decided by `isAbleTo()` so a direct `permission_user`
     * grant is honoured the same way route middleware already is.
     */
    public function canAccessWorkplace(string $permission, int $workplaceId): bool
    {
        if ($this->canAccessAllWorkplaces($permission)) {
            return true;
        }

        return $this->isAbleTo($permission, $workplaceId);
    }

    /**
     * Get accessible workplaces grouped by permission prefix
     *
     * @param array $prefixes Permission prefixes (e.g., ['shift', 'work_history'])
     * @return array Associative array where keys are prefixes and values are workplace ID arrays or null
     */
    public function getAccessibleWorkplacesByPermissionGroup(array $prefixes): array
    {
        // GlobalAdmin has access to all workplaces for all permissions
        if ($this->hasRole(RoleName::GlobalAdmin->value)) {
            return array_fill_keys($prefixes, null);
        }

        $result = [];

        // Get all roles with their team_id and permissions
        $rolesWithPermissions = $this->roles()->with('permissions')->get();

        foreach ($prefixes as $prefix) {
            $teamIds = collect();

            // Check each role for permissions matching this prefix
            foreach ($rolesWithPermissions as $role) {
                $hasMatchingPermission = $role->permissions->contains(function ($permission) use ($prefix) {
                    return str_starts_with($permission->name, $prefix . '.');
                });

                if ($hasMatchingPermission) {
                    // Add the team_id from role_user pivot
                    $teamIds->push($role->pivot->team_id);
                }
            }

            if ($teamIds->isEmpty()) {
                // No access if no permission found with this prefix
                $result[$prefix] = [];
            } elseif ($teamIds->contains(null)) {
                // If any team_id is NULL, user has global access for this prefix
                $result[$prefix] = null;
            } else {
                // Filter out nulls, get unique workplace IDs
                $result[$prefix] = $teamIds->filter()->unique()->values()->toArray();
            }
        }

        return $result;
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public static function getNextStaffNo()
    {
        $maxStaffNo = (int) User::query()->selectRaw('MAX(CAST(staff_no AS SIGNED)) as max')->first()->max;

        return max($maxStaffNo, config('work.staff_no_start')) + 1;
    }

    public static function deletedEmailFor(string $originalEmail): string
    {
        return 'del' . now()->timestamp . '_' . $originalEmail;
    }

    public static function originalEmailFromDeleted(string $deletedEmail): ?string
    {
        if (preg_match('/^del\d+_(.+)$/', $deletedEmail, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function findDisabledByOriginalEmail(string $email, bool $lockForUpdate = false): ?self
    {
        $safeEmail = addcslashes($email, '%_\\');
        $query = static::query()
            ->where('is_disabled', true)
            ->where('email', 'LIKE', 'del%\_' . $safeEmail)
            ->latest('updated_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->first(fn(self $user) => static::originalEmailFromDeleted($user->email) === $email);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_disabled' => 'boolean',
            'is_test' => 'boolean',
            'has_pending_documents' => 'boolean',
            'has_pending_votes' => 'boolean',
            'settings' => 'array',
        ];
    }
}
