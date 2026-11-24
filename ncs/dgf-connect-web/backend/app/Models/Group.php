<?php

namespace App\Models;

use App\Enums\GroupKind;
use App\Enums\SourceTier;
use App\ValueObjects\GroupIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property GroupKind $kind
 * @property string|null $name
 * @property array|null $display_name
 * @property int|null $source_workplace_id
 * @property string|null $source_prefecture
 * @property SourceTier|null $source_tier
 * @property array|null $predicate
 * @property string $identity_key
 */
class Group extends AbstractModel
{
    protected $guarded = [];

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class, 'source_workplace_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_groups')->using(UserGroup::class)->withTimestamps();
    }

    public function userGroups()
    {
        return $this->hasMany(UserGroup::class);
    }

    public function isSystem(): bool
    {
        return $this->kind->isSystem();
    }

    public function isAdmin(): bool
    {
        return $this->kind->isAdmin();
    }

    public static function firstOrCreateByIdentity(GroupIdentity $identity): self
    {
        return self::firstOrCreate(['identity_key' => $identity->key()], $identity->toAttributes());
    }

    protected function casts(): array
    {
        return [
            'kind' => GroupKind::class,
            'source_tier' => SourceTier::class,
            'predicate' => 'array',
            'display_name' => 'array',
        ];
    }
}
