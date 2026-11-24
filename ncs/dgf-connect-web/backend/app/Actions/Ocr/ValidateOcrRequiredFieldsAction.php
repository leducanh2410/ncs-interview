<?php

namespace App\Actions\Ocr;

use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Services\External\OpenRouterOcrService;

class ValidateOcrRequiredFieldsAction
{
    private const REQUIRED_FIELDS = [
        OpenRouterOcrService::GROUP_RESIDENCE_CARD => [
            'name',
            'birthday',
            'address',
            'residence_number',
            'residence_expiry_date',
            'residence_status_raw',
        ],
        OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN => [
            'name',
            'residence_number',
            'residence_expiry_date',
            'residence_status_raw',
        ],
        OpenRouterOcrService::GROUP_PASSPORT => ['passport_number', 'passport_expiry_date'],
    ];

    /**
     * @throws OcrRequiredFieldsMissingException
     */
    public function __invoke(string $group, array $fields): void
    {
        $missingFields = array_values(
            array_filter(self::REQUIRED_FIELDS[$group] ?? [], fn(string $field) => ($fields[$field] ?? null) === null)
        );

        if ($missingFields !== []) {
            throw new OcrRequiredFieldsMissingException($missingFields);
        }
    }
}
