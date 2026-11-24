<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeSheet;

class ShiftOrderImport implements ToCollection, WithCalculatedFormulas, WithEvents
{
    private ?Collection $rows = null;

    private ?string $sheetName = null;

    /**
     * @param Collection<int, Collection<int, mixed>> $rows
     */
    public function collection(Collection $rows): void
    {
        $this->rows ??= $rows;
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->sheetName ??= $event->getSheet()->getTitle();
            },
        ];
    }

    /**
     * @return Collection<int, Collection<int, mixed>>
     */
    public function rows(): Collection
    {
        return $this->rows ?? collect();
    }

    public function sheetName(): ?string
    {
        return $this->sheetName;
    }
}
