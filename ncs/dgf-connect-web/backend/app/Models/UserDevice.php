<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 */
class UserDevice extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['fcm_token', 'created_at', 'updated_at'];

    /**
     * Clients store versioned platform labels such as "iOS 26.5" or "Android 35",
     * so platform checks must match by prefix, never by exact value.
     */
    public function isIos(): bool
    {
        return str_starts_with(mb_strtolower((string) $this->platform), 'ios');
    }

    public function scopeIos(Builder $query): Builder
    {
        return $query->whereRaw("LOWER(platform) LIKE 'ios%'");
    }
}
