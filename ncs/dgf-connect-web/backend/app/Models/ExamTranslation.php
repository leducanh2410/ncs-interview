<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class ExamTranslation extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['created_at', 'updated_at'];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
