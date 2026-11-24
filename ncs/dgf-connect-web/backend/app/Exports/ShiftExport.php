<?php

namespace App\Exports;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ShiftExport implements FromCollection, ShouldAutoSize, ShouldQueue, WithDefaultStyles, WithHeadings, WithStyles
{
    use RegistersEventListeners;

    private readonly Collection $data;

    private readonly Collection $workplacePositions;

    public function __construct(Collection $data, Collection $workplacePositions)
    {
        $this->data = $data;
        $this->workplacePositions = $workplacePositions;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            [
                '通番',
                'スタッフNo.',
                '名前',
                'フリガナ',
                '性別',
                '年少者',
                '国籍',
                '日本語レベル',
                '作業区分',
                '経験作業区分',
            ],
            ['', '', '', '', '', '', '', '', '', ...$this->workplacePositions, '出勤回数', '勤務初日'],
        ];
    }

    public function defaultStyles(Style $defaultStyle): array
    {
        return [
            'alignment' => [
                'wrapText' => true,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    /**
     * @throws Exception
     */
    public function styles(Worksheet $sheet): void
    {
        $positionsCount = $this->workplacePositions->count();
        $lastMergeCol = 10 + $positionsCount - 1;
        $sheet->mergeCells([10, 1, $lastMergeCol, 1], Worksheet::MERGE_CELL_CONTENT_MERGE);
        for ($i = 1; $i < 10; $i++) {
            $sheet->mergeCells([$i, 1, $i, 2], Worksheet::MERGE_CELL_CONTENT_MERGE);
        }
        $sheet->mergeCells([$lastMergeCol + 1, 1, $lastMergeCol + 1, 2], Worksheet::MERGE_CELL_CONTENT_MERGE);
        $sheet->mergeCells([$lastMergeCol + 2, 1, $lastMergeCol + 2, 2], Worksheet::MERGE_CELL_CONTENT_MERGE);

        foreach (range(0, 1) as $i) {
            $col = 10 + $this->workplacePositions->count() + $i;
            $sheet->mergeCells([$col, 1, $col, 2], Worksheet::MERGE_CELL_CONTENT_MERGE);
        }
        $sheet->getStyle('B:C')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet
            ->getStyle($sheet->getHighestColumn())
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $lastCell = $sheet->getHighestRowAndColumn();
        $sheet->getStyle("A1:{$lastCell['column']}{$lastCell['row']}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }
}
