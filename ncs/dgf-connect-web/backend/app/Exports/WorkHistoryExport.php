<?php

namespace App\Exports;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WorkHistoryExport implements
    FromCollection,
    ShouldAutoSize,
    ShouldQueue,
    WithDefaultStyles,
    WithHeadings,
    WithStrictNullComparison,
    WithStyles
{
    use RegistersEventListeners;

    private readonly Collection $data;

    private readonly int $total;

    public function __construct(Collection $data, int $total)
    {
        $this->data = $data;
        $this->total = $total;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            [
                'Mã nhân viên',
                'Tên nhân viên',
                'Số ngày làm',
                'Tổng giờ làm',
                'Số giờ bình thường',
                'Số giờ tăng 25%',
                'Số giờ tăng 50%',
            ],
        ];
    }

    public function defaultStyles(Style $defaultStyle): array
    {
        return [
            'alignment' => [
                'wrapText' => true,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    /**
     * @throws Exception
     */
    public function styles(Worksheet $sheet): void
    {
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('C1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('D:G')->getNumberFormat()->setFormatCode('[H]:mm');
        $lastCell = $sheet->getHighestRowAndColumn();

        $tableLastRow = $this->total + 1;
        $summaryRow = $this->total + 3;
        $sheet
            ->getStyle("A{$summaryRow}:A{$lastCell['row']}")
            ->getFont()
            ->setBold(true);
        $sheet->getStyle("A1:{$lastCell['column']}{$tableLastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }
}
