<?php

namespace App\Enums;

enum PdfTemplateFieldFillMode: string
{
    use EnumTrait;

    case Text = 'text';
    case Shape = 'shape';
}
