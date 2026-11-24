<?php

namespace App\Enums;

enum ExamType: string
{
    use EnumTrait;

    case Assessment = 'assessment';
    case ExternalFormPrep = 'external_form_prep';
}
