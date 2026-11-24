<?php

namespace App\Models;

/**
 * @property int $id
 * @property int $question_category_id
 * @property string $lang_code
 * @property string $topic
 * @property string $title
 */
class QuestionCategoryTranslation extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
}
