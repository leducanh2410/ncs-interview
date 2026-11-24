<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class UserListExcelImport implements ToCollection, WithCalculatedFormulas, WithCustomCsvSettings, WithMultipleSheets
{
    private ?Collection $rows = null;

    public function __construct(private readonly string $csvInputEncoding = Csv::GUESS_ENCODING) {}

    /**
     * @param Collection<int, Collection<int, mixed>> $rows
     */
    public function collection(Collection $rows): void
    {
        $this->rows ??= $rows;
    }

    /**
     * @return array<int, self>
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    /**
     * @return array{input_encoding: string}
     */
    public function getCsvSettings(): array
    {
        return ['input_encoding' => $this->csvInputEncoding];
    }

    /**
     * @return Collection<int, Collection<int, mixed>>
     */
    public function rows(): Collection
    {
        return $this->rows ?? collect();
    }
}
