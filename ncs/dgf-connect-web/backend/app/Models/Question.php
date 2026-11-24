<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatableContent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends AbstractModel
{
    use HasTranslatableContent;

    protected $guarded = [];

    protected $casts = [];

    protected $hidden = ['created_at', 'updated_at'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('id');
    }

    public function translatableField(): string
    {
        return 'content';
    }
}
