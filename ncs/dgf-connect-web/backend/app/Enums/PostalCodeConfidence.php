<?php

namespace App\Enums;

enum PostalCodeConfidence: string
{
    use EnumTrait;

    // Exactly one postal code matches the address
    case Exact = 'exact';

    // The town is split across several codes (by 丁目 or 番地) and the address does not settle it
    case Ambiguous = 'ambiguous';

    // Only the city was recognised; the city's catch-all code is offered as a candidate
    case CityDefault = 'city_default';

    // Not even the city could be recognised
    case None = 'none';
}
