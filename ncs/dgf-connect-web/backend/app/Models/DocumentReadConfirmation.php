<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $document_required_config_id
 * @property string $ip_address
 * @property array|null $device_info
 */
class DocumentReadConfirmation extends AbstractModel
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(DocumentRequiredConfig::class, 'document_required_config_id');
    }

    protected function casts(): array
    {
        return [
            'device_info' => 'array',
        ];
    }
}
