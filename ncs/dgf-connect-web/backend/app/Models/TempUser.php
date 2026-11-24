<?php

namespace App\Models;

/**
 * @property string $email
 * @property string $password
 */
class TempUser extends AbstractModel
{
    protected $guarded = [];

    protected $hidden = ['password'];
}
