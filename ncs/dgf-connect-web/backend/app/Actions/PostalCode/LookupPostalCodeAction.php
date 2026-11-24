<?php

namespace App\Actions\PostalCode;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\JapanPostalCode;
use App\Support\Address\JapaneseAddressNormalizer;

/**
 * Forward lookup: the areas covered by a postal code.
 */
class LookupPostalCodeAction
{
    public function __invoke(string $code): ActionResult
    {
        $postalCode = JapaneseAddressNormalizer::normalizePostalCode($code);
        if ($postalCode === null) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: ['message' => 'A postal code must be 7 digits']
            );
        }

        $areas = JapanPostalCode::query()
            ->where('postal_code', $postalCode)
            ->orderBy('id')
            ->get()
            ->map(fn(JapanPostalCode $row) => $row->toCandidate())
            ->values()
            ->all();

        return new ActionResult(data: ['postal_code' => $postalCode, 'areas' => $areas]);
    }
}
