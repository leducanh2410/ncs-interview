<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $description
 */
class QuestionSet extends AbstractModel
{
    protected $guarded = [];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function stressCheckPeriods(): HasMany
    {
        return $this->hasMany(StressCheckPeriod::class);
    }
}
