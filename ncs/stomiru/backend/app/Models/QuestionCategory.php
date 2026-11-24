<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 */
class QuestionCategory extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(QuestionCategoryTranslation::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
