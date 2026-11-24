<?php

namespace App\Exports;

use App\Models\FixedScheduleChangeRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FixedScheduleChangeRequestExport implements
    FromCollection,
    ShouldAutoSize,
    ShouldQueue,
    WithDefaultStyles,
    WithHeadings,
    WithMapping,
    WithStyles
{
    use RegistersEventListeners;

    private readonly Collection $data;

    public function __construct(Collection $data)
    {
        $this->data = $data;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [['Nhân viên', 'Xưởng', 'Số ngày thay đổi', 'Trạng thái', 'Gửi lúc', 'Xử lý lúc']];
    }

    /**
     * @param FixedScheduleChangeRequest $request
     */
    public function map($request): array
    {
        return [
            $request->user?->short_name,
            $request->workplace?->name,
            $request->days->count(),
            $request->status,
            $request->created_at?->format('Y-m-d H:i'),
            $request->processed_at?->format('Y-m-d H:i'),
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
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $lastCell = $sheet->getHighestRowAndColumn();
        $sheet->getStyle("A1:{$lastCell['column']}{$lastCell['row']}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }
}
