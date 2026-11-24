<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Traits\FilterQuery;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;

/**
 * @property int $id
 * @property int $company_id
 * @property string $login_id
 * @property string $first_name
 * @property string $last_name
 * @property string $password
 * @property bool $is_password_changed
 * @property string $status
 * @property string $lang_code
 * @property string $created_at
 * @property string $updated_at
 */
class User extends Authenticatable implements LaratrustUser
{
    use Filterable, FilterQuery;
    use HasFactory, Notifiable;
    use HasRolesAndPermissions;

    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'temp_password',
        'remember_token',
        'created_at',
        'updated_at',
    ];

    protected $appends = [
        'name',
    ];

    public array $sortable = [
        'employee_code',
        'name',
        'role',
        'employee_status',
        'email',
        'status',
    ];

    public function accessTokens(): HasMany
    {
        return $this->hasMany(AccessToken::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function userProfile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function stressCheckPeriods(): HasMany
    {
        return $this->hasMany(StressCheckPeriod::class);
    }

    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
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

    public function getNameAttribute(): string
    {
        return $this->last_name.' '.$this->first_name;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_password_changed' => 'boolean',
        ];
    }
}
