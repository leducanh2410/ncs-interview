<?php

namespace App\Support\Address;

use App\Enums\PostalCodeConfidence;

readonly class PostalCodeResolution
{
    /**
     * @param ?string $postalCode seven digits; only set when the confidence is Exact
     * @param array<int, array{postal_code: string, prefecture: string, city: string, town: string, town_detail: ?string}> $candidates
     */
    public function __construct(
        public ?string $postalCode,
        public PostalCodeConfidence $confidence,
        public ParsedJapaneseAddress $matched,
        public array $candidates = []
    ) {}

    public function isExact(): bool
    {
        return $this->confidence === PostalCodeConfidence::Exact && $this->postalCode !== null;
    }

    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'confidence' => $this->confidence->value,
            'matched' => $this->matched->toArray(),
            'candidates' => $this->candidates,
        ];
    }
}
