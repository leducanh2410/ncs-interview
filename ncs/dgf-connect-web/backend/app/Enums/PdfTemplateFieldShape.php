<?php

namespace App\Enums;

enum PdfTemplateFieldShape: string
{
    use EnumTrait;

    case Rectangle = 'rectangle';
    case Circle = 'circle';
}
