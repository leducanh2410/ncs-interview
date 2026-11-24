<?php

namespace App\Enums;

enum PollType: string
{
    use EnumTrait;

    case Vote = 'vote';
    case Feedback = 'feedback';
}
