<?php

namespace App\Models;

use App\Enums\PdfTemplateFieldFillMode;
use App\Enums\PdfTemplateFieldShape;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pdf_template_id
 * @property int $page
 * @property PdfTemplateFieldShape $shape
 * @property float $x
 * @property float $y
 * @property float $width
 * @property float $height
 * @property string $data_source_key
 * @property string|null $placeholder
 * @property bool $invert_value
 * @property PdfTemplateFieldFillMode $fill_mode
 * @property array|null $text_config
 * @property array|null $shape_config
 */
class PdfTemplateField extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'shape' => PdfTemplateFieldShape::class,
        'fill_mode' => PdfTemplateFieldFillMode::class,
        'text_config' => 'array',
        'shape_config' => 'array',
        'invert_value' => 'boolean',
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
    ];

    public function pdfTemplate(): BelongsTo
    {
        return $this->belongsTo(PdfTemplate::class);
    }
}
