<?php

namespace App\Enums;

enum Gender: string
{
    use EnumTrait;

    case Male = 'male';
    case Female = 'female';
}
