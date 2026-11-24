<?php

namespace App\Actions\Ocr;

use App\Actions\PostalCode\ResolvePostalCodeFromAddressAction;
use App\Services\External\OpenRouterOcrService;

/**
 * Adds `postal_code` to residence card OCR fields by resolving the extracted address
 * against Japan Post data. The LLM is deliberately not asked for the code: it is an
 * arbitrary digit string it can only recall, and a wrong guess looks just as plausible.
 */
class EnrichOcrFieldsWithPostalCodeAction
{
    public const WARNING_UNRESOLVED = 'postal_code_unresolved';

    private const GROUPS = [
        OpenRouterOcrService::GROUP_RESIDENCE_CARD,
        OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN,
    ];

    public function __construct(private readonly ResolvePostalCodeFromAddressAction $resolve) {}

    /**
     * @param array{fields: array, warnings: string[]} $normalized
     * @return array{fields: array, warnings: string[]}
     */
    public function __invoke(string $group, array $normalized): array
    {
        if (!in_array($group, self::GROUPS, true)) {
            return $normalized;
        }

        $address = $normalized['fields']['address'] ?? null;
        if (!is_string($address) || $address === '') {
            $normalized['fields']['postal_code'] = null;

            return $normalized;
        }

        $resolution = ($this->resolve)($address, $normalized['fields']['prefecture'] ?? null);
        $normalized['fields']['postal_code'] = $resolution->isExact() ? $resolution->postalCode : null;
        if (!$resolution->isExact()) {
            $normalized['warnings'][] = self::WARNING_UNRESOLVED;
        }

        return $normalized;
    }
}
