<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait BuildsUserListWorkbook
{
    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function fillSheet(Worksheet $sheet, array $rows): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                if ($value === null) {
                    continue;
                }

                $sheet->setCellValueExplicit(
                    [$columnIndex + 1, $rowIndex + 1],
                    $value,
                    is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING
                );
            }
        }
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, array<int, mixed>>|null $secondSheetRows
     */
    private function userListWorkbook(
        array $rows,
        string $fileName = 'user-list.xlsx',
        ?array $secondSheetRows = null
    ): UploadedFile {
        $spreadsheet = new Spreadsheet();
        $this->fillSheet($spreadsheet->getActiveSheet(), $rows);

        if ($secondSheetRows !== null) {
            $this->fillSheet($spreadsheet->createSheet(), $secondSheetRows);
        }

        $path = tempnam(sys_get_temp_dir(), 'userlist') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            $fileName,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
