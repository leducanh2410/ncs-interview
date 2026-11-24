<?php

namespace App\Support\Address;

/**
 * The parts of an address that Japan Post data can recognise. Every part is the
 * original text from the imported data (not the normalized key), and `rest` is
 * the normalized remainder after the recognised parts, i.e. 番地 and building.
 */
readonly class ParsedJapaneseAddress
{
    public function __construct(
        public ?string $prefecture,
        public ?string $city,
        public ?string $town,
        public string $rest
    ) {}

    public function toArray(): array
    {
        return [
            'prefecture' => $this->prefecture,
            'city' => $this->city,
            'town' => $this->town,
            'rest' => $this->rest,
        ];
    }
}
