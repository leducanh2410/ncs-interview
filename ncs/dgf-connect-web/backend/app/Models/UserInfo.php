<?php

namespace App\Models;

use App\Support\User\UserInfoNormalizer;

class UserInfo extends AbstractModel
{
    /**
     * `user_id` is a foreign key, not an auto-increment column. Without this,
     * Eloquent overwrites it in-memory with insertGetId()'s (meaningless)
     * return value right after INSERT, even though the row itself is written
     * correctly — corrupting any code that reads the model's `user_id`
     * immediately after `create()`.
     */
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $guarded = [];

    public function setAddressAttribute(?string $value): void
    {
        $this->attributes['address'] = app(UserInfoNormalizer::class)->address($value);
    }

    public function setPrefectureAttribute(?string $value): void
    {
        $this->attributes['prefecture'] = app(UserInfoNormalizer::class)->prefecture($value);
    }
}
