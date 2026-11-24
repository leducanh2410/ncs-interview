<?php

namespace Tests\Unit\Support\BankAccount;

use App\Support\BankAccount\BankAccountInputComparator;
use Tests\TestCase;

class BankAccountInputComparatorTest extends TestCase
{
    private BankAccountInputComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comparator = new BankAccountInputComparator();
    }

    public function testEquivalentValuesProduceNoMismatch(): void
    {
        $ocr = [
            'bank_name' => '三菱UFJ銀行',
            'branch_name' => '新宿支店',
            'branch_code' => '21',
            'account_number' => '0012345',
            'holder_name_kana' => 'ｸﾞｴﾑ ﾊﾟﾝ ｴｰ',
        ];
        $input = [
            'bank_name' => '三菱ＵＦＪ',
            'branch_name' => '新宿',
            'branch_code' => '021',
            'account_number' => '12345',
            'account_holder_kana' => 'グエム パン エー',
        ];

        $this->assertSame([], $this->comparator->compare($ocr, $input));
    }

    public function testDifferentValuesAreReportedPerField(): void
    {
        $ocr = ['account_number' => '1234567', 'branch_code' => '021', 'holder_name_kana' => 'グエン バン アン'];
        $input = ['account_number' => '1234568', 'branch_code' => '021', 'account_holder_kana' => 'タナカ タロウ'];

        $mismatches = $this->comparator->compare($ocr, $input);

        $this->assertSame(['account_number', 'account_holder_kana'], array_column($mismatches, 'field'));
        $this->assertSame('1234567', $mismatches[0]['ocr_value']);
        $this->assertSame('1234568', $mismatches[0]['input_value']);
    }

    public function testUnreadableOcrFieldsAreSkipped(): void
    {
        $ocr = ['account_number' => null, 'bank_name' => null, 'holder_name_kana' => null];
        $input = ['account_number' => '1234567', 'bank_name' => 'みずほ銀行', 'account_holder_kana' => 'タナカ'];

        $this->assertSame([], $this->comparator->compare($ocr, $input));
    }
}
