<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $file_path
 * @property string $file_name
 * @property int $page_count
 * @property int $field_count
 * @property array|null $lock_info
 */
class PdfTemplate extends AbstractModel
{
    use SoftDeletes;

    protected $guarded = [];

    protected $appends = ['field_count', 'lock_info'];

    public function fields(): HasMany
    {
        return $this->hasMany(PdfTemplateField::class);
    }

    public function exportBatches(): HasMany
    {
        return $this->hasMany(ExportBatch::class);
    }

    public function getFieldCountAttribute(): int
    {
        return $this->fields()->count();
    }

    public function getLockInfoAttribute(): ?array
    {
        return $this->attributes['lock_info'] ?? null;
    }
}
