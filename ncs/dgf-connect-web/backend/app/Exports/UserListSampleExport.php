<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class UserListSampleExport implements FromArray, WithEvents, WithHeadings
{
    private const EXAMPLE_ROWS = [['0001', 'SAMPLE TARO'], ['0002', 'SAMPLE HANAKO']];

    public function __construct(private readonly string $locale) {}

    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return [__('pdf_export.sample.staff_no', [], $this->locale), __('pdf_export.sample.name', [], $this->locale)];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                $sheet->getColumnDimension('A')->setWidth(16);
                $sheet->getColumnDimension('B')->setWidth(28);

                foreach (self::EXAMPLE_ROWS as $index => [$staffNo, $name]) {
                    $rowNumber = $index + 2;
                    $sheet->setCellValueExplicit("A{$rowNumber}", $staffNo, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("B{$rowNumber}", $name, DataType::TYPE_STRING);
                }
            },
        ];
    }
}
