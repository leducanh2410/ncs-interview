<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatableContent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends AbstractModel
{
    use HasTranslatableContent;

    protected $guarded = [];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function translatableField(): string
    {
        return 'content';
    }
}
