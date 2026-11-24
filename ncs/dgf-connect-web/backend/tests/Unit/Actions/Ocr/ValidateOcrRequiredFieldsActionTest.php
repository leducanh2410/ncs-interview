<?php

namespace Tests\Unit\Actions\Ocr;

use App\Actions\Ocr\ValidateOcrRequiredFieldsAction;
use App\Exceptions\OcrRequiredFieldsMissingException;
use App\Services\External\OpenRouterOcrService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidateOcrRequiredFieldsActionTest extends TestCase
{
    #[DataProvider('requiredFieldProvider')]
    public function testRejectsEachMissingRequiredField(string $group, array $fields, string $missingField): void
    {
        $fields[$missingField] = null;

        try {
            (new ValidateOcrRequiredFieldsAction())($group, $fields);
            $this->fail('A missing required OCR field should be rejected');
        } catch (OcrRequiredFieldsMissingException $e) {
            $this->assertSame([$missingField], $e->missingFields);
            $this->assertSame(OcrRequiredFieldsMissingException::ERROR_CODE, $e->getMessage());
        }
    }

    public function testAcceptsCompleteRequiredFields(): void
    {
        $action = new ValidateOcrRequiredFieldsAction();

        $action(OpenRouterOcrService::GROUP_RESIDENCE_CARD, $this->residenceCardFields());
        $action(OpenRouterOcrService::GROUP_PASSPORT, $this->passportFields());

        $this->addToAssertionCount(1);
    }

    public function testDoesNotValidateStudentCardFields(): void
    {
        (new ValidateOcrRequiredFieldsAction())(OpenRouterOcrService::GROUP_STUDENT_CARD, [
            'student_card_expiry_date' => null,
        ]);

        $this->addToAssertionCount(1);
    }

    public static function requiredFieldProvider(): array
    {
        $residenceFields = self::residenceCardFields();
        $passportFields = self::passportFields();

        return [
            'residence name' => [OpenRouterOcrService::GROUP_RESIDENCE_CARD, $residenceFields, 'name'],
            'residence birthday' => [OpenRouterOcrService::GROUP_RESIDENCE_CARD, $residenceFields, 'birthday'],
            'residence address' => [OpenRouterOcrService::GROUP_RESIDENCE_CARD, $residenceFields, 'address'],
            'residence number' => [OpenRouterOcrService::GROUP_RESIDENCE_CARD, $residenceFields, 'residence_number'],
            'residence expiry date' => [
                OpenRouterOcrService::GROUP_RESIDENCE_CARD,
                $residenceFields,
                'residence_expiry_date',
            ],
            'residence status' => [
                OpenRouterOcrService::GROUP_RESIDENCE_CARD,
                $residenceFields,
                'residence_status_raw',
            ],
            'passport number' => [OpenRouterOcrService::GROUP_PASSPORT, $passportFields, 'passport_number'],
            'passport expiry date' => [OpenRouterOcrService::GROUP_PASSPORT, $passportFields, 'passport_expiry_date'],
        ];
    }

    private static function residenceCardFields(): array
    {
        return [
            'name' => 'NGUYEN VAN A',
            'birthday' => '1998-04-12',
            'address' => '東京都新宿区',
            'residence_number' => 'AB12345678CD',
            'residence_expiry_date' => '2027-01-31',
            'residence_status_raw' => '留学',
        ];
    }

    private static function passportFields(): array
    {
        return [
            'passport_number' => 'C1234567',
            'passport_expiry_date' => '2030-06-01',
        ];
    }
}
