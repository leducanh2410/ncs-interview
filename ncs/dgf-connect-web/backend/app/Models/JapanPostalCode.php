<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $postal_code
 * @property string $prefecture
 * @property string $city
 * @property string $city_key
 * @property string $town
 * @property string $town_key
 * @property ?string $town_detail
 * @property bool $is_city_default
 * @property string $row_hash
 * @property string $import_batch
 */
class JapanPostalCode extends AbstractModel
{
    protected $guarded = [];

    protected $casts = [
        'is_city_default' => 'boolean',
    ];

    /**
     * @return array{postal_code: string, prefecture: string, city: string, town: string, town_detail: ?string}
     */
    public function toCandidate(): array
    {
        return [
            'postal_code' => $this->postal_code,
            'prefecture' => $this->prefecture,
            'city' => $this->city,
            'town' => $this->town,
            'town_detail' => $this->town_detail,
        ];
    }
}
