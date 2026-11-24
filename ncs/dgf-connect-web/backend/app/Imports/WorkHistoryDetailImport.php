<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

/**
 * Reads an uploaded Work_History_Detail_Export workbook (see
 * App\Exports\WorkHistoryDetailExport) as a raw grid of rows, without Maatwebsite's automatic
 * heading-row mapping - the file has a fixed, position-based 17-column layout with grouped
 * workplace-header/blank-separator rows, not a flat heading-keyed table, so the caller
 * (PreviewBulkImportWorkHistoryEditRequestsAction) validates the header row and classifies rows
 * itself.
 */
class WorkHistoryDetailImport implements ToCollection, WithCalculatedFormulas
{
    private ?Collection $rows = null;

    /**
     * @param Collection<int, Collection<int, mixed>> $rows
     */
    public function collection(Collection $rows): void
    {
        // A single-sheet upload only ever yields one collection via toCollection(); store it so
        // the caller can retrieve it after Excel::import() finishes.
        $this->rows = $rows;
    }

    /**
     * @return Collection<int, Collection<int, mixed>>
     */
    public function rows(): Collection
    {
        return $this->rows ?? collect();
    }
}
