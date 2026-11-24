<?php

namespace App\Models;

use App\Enums\LogAction;
use App\Enums\LogCategory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $actor_id
 * @property string $actor_name
 * @property string $actor_role
 * @property string $category
 * @property int $target_id
 * @property string $action
 */
class Log extends AbstractModel
{
    protected $guarded = [];

    public array $sortable = [
        'id',
        'created_at',
    ];

    protected $casts = [
        'actor_role' => 'array',
        'action' => LogAction::class,
        'category' => LogCategory::class,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
