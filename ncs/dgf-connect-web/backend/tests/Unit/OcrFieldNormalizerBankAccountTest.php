<?php

namespace Tests\Unit;

use App\Services\External\OpenRouterOcrService;
use App\Support\Ocr\OcrFieldNormalizer;
use Tests\TestCase;

class OcrFieldNormalizerBankAccountTest extends TestCase
{
    public function testPadsNumbersAndNormalizesKana(): void
    {
        $result = (new OcrFieldNormalizer())->normalize(OpenRouterOcrService::GROUP_BANK_ACCOUNT, [
            'bank_name' => ' 三菱UFJ銀行 ',
            'bank_code' => '5',
            'branch_name' => '新宿支店',
            'branch_code' => '21',
            'account_type' => 'ORDINARY',
            'account_number' => '12-34567',
            'holder_name_kana' => 'ｸﾞｴﾝ  ﾊﾞﾝ ｴｰ',
            'holder_name_latin' => 'nguyen van an',
            'is_yucho' => false,
            'yucho_symbol' => null,
            'yucho_number' => null,
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame('三菱UFJ銀行', $result['fields']['bank_name']);
        $this->assertSame('0005', $result['fields']['bank_code']);
        $this->assertSame('021', $result['fields']['branch_code']);
        $this->assertSame('ordinary', $result['fields']['account_type']);
        $this->assertSame('1234567', $result['fields']['account_number']);
        $this->assertSame('グエン バン エー', $result['fields']['holder_name_kana']);
        $this->assertSame('NGUYEN VAN AN', $result['fields']['holder_name_latin']);
        $this->assertFalse($result['fields']['is_yucho']);
    }

    public function testInvalidValuesAreDroppedWithWarnings(): void
    {
        $result = (new OcrFieldNormalizer())->normalize(OpenRouterOcrService::GROUP_BANK_ACCOUNT, [
            'account_type' => 'fixed',
            'account_number' => '123456789',
            'branch_code' => 'abc',
            'is_yucho' => true,
            'yucho_symbol' => '10230',
            'yucho_number' => '12345671',
        ]);

        $this->assertNull($result['fields']['account_type']);
        $this->assertNull($result['fields']['account_number']);
        $this->assertNull($result['fields']['branch_code']);
        $this->assertTrue($result['fields']['is_yucho']);
        $this->assertSame('10230', $result['fields']['yucho_symbol']);
        $this->assertSame('12345671', $result['fields']['yucho_number']);
        $this->assertEqualsCanonicalizing(
            ['account_type_invalid_value', 'account_number_format', 'branch_code_format'],
            $result['warnings']
        );
    }
}
