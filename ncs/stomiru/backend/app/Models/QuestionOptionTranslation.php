<?php

namespace App\Models;

/**
 * @property int $id
 * @property int $question_option_id
 * @property string $lang_code
 * @property string $content
 */
class QuestionOptionTranslation extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
}
