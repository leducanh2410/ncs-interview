# Implementation Plan: Work History Detail Export Filter by Workplace

## Overview

This is a single-file change to `WorkHistoryDetailExport.php`. The implementation order is: update headings first, then modify the constructor to prepend workplace name to data rows, then update the `styles()` method to remove merging and fix column references. All changes are in the same class so there are no cross-file dependencies.

## Tasks

- [x] 1. Update column headings
    - [x] 1.1 Add "Xưởng" as the first element in the `headings()` return array
        - Prepended `'Xưởng'` before `'Ngày làm'` in the headings array
        - Result: 16 column headers (A–P)
        - _Requirements: 1.1, 1.2, 1.4_

- [x] 2. Add workplace name to data rows
    - [x] 2.1 Prepend `workplace_name` to each data row in the constructor
        - Added `'workplace_name' => Arr::get($item, 'workplace_name')` as the first key in the pushed array
        - _Requirements: 1.3, 1.4_

- [x] 3. Update styles and formatting
    - [x] 3.1 Remove `mergeCells()` from workplace header rows
        - Removed `$sheet->mergeCells(...)` call and full-range style
        - Bold styling now applies only to cell `A{$row}`
        - _Requirements: 2.1, 2.2, 2.3, 3.3_

    - [x] 3.2 Update header row bold range
        - Changed `$sheet->getStyle('A1:O1')` to `$sheet->getStyle('A1:P1')`
        - _Requirements: 4.4_

    - [x] 3.3 Update text format column references
        - Column A (workplace): `FORMAT_TEXT`
        - Column B (date): `FORMAT_TEXT`
        - Column D (staff_no): `FORMAT_TEXT`
        - _Requirements: 4.1, 4.2_

    - [x] 3.4 Update time format column range
        - Changed `$sheet->getStyle('H:O')` to `$sheet->getStyle('I:P')`
        - _Requirements: 4.3_

    - [x] 3.5 Verify border range
        - Uses `getHighestRowAndColumn()` which dynamically detects column P — no change needed
        - _Requirements: 4.5_

- [x] 4. Checkpoint - Verify export output
    - Trigger the export via the API or tinker and open the resulting `.xlsx` file
    - Verify: 16 columns, workplace name on every data row in column A, no merged cells, bold header rows, correct number formats, borders intact, empty separator rows preserved
    - Ensure all existing tests still pass: `docker compose exec php ./vendor/bin/phpunit`

## Notes

- All changes are in `backend/app/Exports/WorkHistoryDetailExport.php` — no other files need modification
- The service layer (`WorkHistoryService::exportList`) already includes `workplace_name` in each data item, so no service changes are needed
- The border range uses `getHighestRowAndColumn()` which dynamically detects the last column, so it auto-adjusts to P without explicit changes
- Task ordering follows the file structure: headings → constructor → styles
