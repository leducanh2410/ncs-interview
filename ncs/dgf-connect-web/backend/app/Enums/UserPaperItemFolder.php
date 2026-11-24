<?php

namespace App\Enums;

enum UserPaperItemFolder: string
{
    case Current = 'current';
    case Old = 'old';
    case Rejected = 'rejected';
    case Deleted = 'deleted';
}
