<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $keyword
 * @property string|null $locale
 * @property string $action
 * @property bool $is_active
 */
class ChatBannedKeyword extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
