<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends AbstractModel
{
    protected $guarded = [];

    protected $casts = ['created_at' => 'date:Y-m-d', 'updated_at' => 'date:Y-m-d'];

    protected $appends = ['url'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function workplaces(): BelongsToMany
    {
        return $this->belongsToMany(Workplace::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function getUrlAttribute(): ?string
    {
        $attachment = $this->relationLoaded('attachments')
            ? $this->attachments->first()
            : $this->attachments()->oldest('id')->first();

        return $attachment?->url;
    }

    public function requiredConfigs(): HasMany
    {
        return $this->hasMany(DocumentRequiredConfig::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (Document $document) {
            DocumentRequiredConfig::query()
                ->where('document_id', $document->id)
                ->update([
                    'document_id' => null,
                    'is_active' => false,
                ]);
        });
    }
}
