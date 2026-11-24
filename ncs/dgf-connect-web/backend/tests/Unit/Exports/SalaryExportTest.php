<?php

namespace Tests\Unit\Exports;

use App\Exports\SalaryExport;
use App\Exports\SalaryRequestExport;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The action tests assert which rows go into the sheet; these render it for real, because the
 * headings and the cell formatting are part of what the payroll and banking systems read
 * back. In particular the identifier columns have to stay text so leading zeros survive.
 */
class SalaryExportTest extends TestCase
{
    public function testTheSalarySheetCarriesThePayrollHeadingsAndKeepsStaffNumbersAsText(): void
    {
        $export = new SalaryExport(
            collect([
                [
                    'staff_no' => '0042',
                    'name' => 'YAMADA TARO',
                    'name_kana' => 'ヤマダ タロウ',
                    'work_days' => 12,
                    'work_hours_normal' => '40:00',
                    'work_hours_overtime' => '2:05',
                    'work_hours_night' => '5:00',
                    'work_hours_night_overtime' => '0:00',
                    'base_salary' => 1000,
                    'bonus' => '',
                    'diff' => 0,
                    'total_paid' => 1000,
                    'total_fee' => 330,
                    'total_deductions' => 200,
                    'total_remaining' => 48470,
                ],
            ])
        );

        $sheet = $this->render($export);

        $this->assertSame('社員コード', $sheet->getCell('A1')->getValue());
        $this->assertSame('差引支給額', $sheet->getCell('O1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        $this->assertSame('0042', $sheet->getCell('A2')->getValue());
        $this->assertSame(NumberFormat::FORMAT_TEXT, $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('40:00', $sheet->getCell('E2')->getValue());
        $this->assertSame(48470, $sheet->getCell('O2')->getValue());
        $this->assertNotSame(
            'none',
            $sheet->getStyle('A2')->getBorders()->getBottom()->getBorderStyle(),
            'Every written cell is boxed in.'
        );
    }

    public function testTheBankSheetCarriesTheRemittanceHeadingsAndKeepsCustomerNumbersAsText(): void
    {
        $export = new SalaryRequestExport(
            collect([
                [
                    'staff_no' => '0042',
                    'staff_no_2' => '0042',
                    'amount' => 51000,
                    'notification_method' => 'Ｅメール',
                    'additional_information_1' => '',
                    'additional_information_2' => '',
                    'additional_information_3' => '',
                    'content' => '前払い',
                    'name_katakana' => '',
                    'name' => 'YAMADATARO',
                    'phone' => '',
                    'email' => 'taro@example.com',
                ],
            ])
        );

        $sheet = $this->render($export);

        $this->assertSame('送金依頼明細番号', $sheet->getCell('A1')->getValue());
        $this->assertSame('メールアドレス', $sheet->getCell('L1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        $this->assertSame('0042', $sheet->getCell('A2')->getValue());
        $this->assertSame('0042', $sheet->getCell('B2')->getValue());
        $this->assertSame(NumberFormat::FORMAT_TEXT, $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame(51000, $sheet->getCell('C2')->getValue());
        $this->assertSame('前払い', $sheet->getCell('H2')->getValue());
        $this->assertSame('taro@example.com', $sheet->getCell('L2')->getValue());
    }

    private function render(object $export): Worksheet
    {
        // tempnam() creates the file it names, and the reader wants the .xlsx suffix to pick
        // its format, so both paths exist and both have to go.
        $reserved = tempnam(sys_get_temp_dir(), 'export');
        $path = $reserved . '.xlsx';
        file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
            @unlink($reserved);
        }
    }
}
