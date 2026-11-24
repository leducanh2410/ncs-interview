<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property array $evaluation_score
 */
class Scale extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'evaluation_score' => 'array',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ScaleTranslation::class);
    }
}
