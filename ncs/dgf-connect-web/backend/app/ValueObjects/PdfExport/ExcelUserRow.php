<?php

namespace App\ValueObjects\PdfExport;

final readonly class ExcelUserRow
{
    public function __construct(public int $rowNumber, public string $staffNo, public ?string $name) {}

    public function isNumericStaffNo(): bool
    {
        return preg_match('/^\d+$/', $this->staffNo) === 1;
    }

    /**
     * @return array{row_number: int, staff_no: string, name: string|null}
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'staff_no' => $this->staffNo,
            'name' => $this->name,
        ];
    }
}
