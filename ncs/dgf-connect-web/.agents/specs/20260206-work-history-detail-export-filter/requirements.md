# Requirements Document

## Introduction

The Work History Detail Export (`WorkHistoryDetailExport`) currently groups check-in records by workplace and renders the workplace name as a merged-cell header row spanning all columns. This makes it difficult to use Excel's built-in filter and sort features on the workplace dimension. This change adds a dedicated "Xưởng" (Workplace) column as the first column (column A) so that every data row carries the workplace name, enabling straightforward filtering. The existing workplace header rows are preserved (unmerged) as visual separators, and the 2 empty rows between groups are retained.

## Glossary

- **Detail_Export**: The `WorkHistoryDetailExport` PHP class responsible for generating the work history detail Excel file
- **Workplace_Header_Row**: The row that currently contains only the workplace name, previously merged across all columns, used as a visual group separator
- **Workplace_Column**: The new column A ("Xưởng") added to every row to hold the workplace name for filtering purposes
- **Data_Row**: A row containing an individual check-in record with time, staff, and work-hour data

## Requirements

### Requirement 1: Add Workplace Column

**User Story:** As a manager, I want every data row in the exported Excel to include the workplace name in a dedicated column, so that I can filter and sort the spreadsheet by workplace.

#### Acceptance Criteria

1. THE Detail_Export SHALL include a new first column (column A) with the header "Xưởng"
2. THE Detail_Export SHALL shift all existing columns one position to the right (previous column A "Ngày làm" becomes column B, through to column P)
3. WHEN a Data_Row is generated, THE Detail_Export SHALL populate the Workplace_Column with the workplace name associated with that check-in record
4. THE spreadsheet SHALL now contain 16 columns (A through P) instead of the previous 15 (A through O)

### Requirement 2: Unmerge Workplace Header Rows

**User Story:** As a manager, I want the workplace header rows to not use merged cells, so that Excel filtering works correctly without merged-cell conflicts.

#### Acceptance Criteria

1. THE Detail_Export SHALL NOT merge cells in Workplace_Header_Row rows
2. WHEN rendering a Workplace_Header_Row, THE Detail_Export SHALL place the workplace name in column A only, leaving columns B through P blank
3. THE Detail_Export SHALL apply bold font styling to the Workplace_Header_Row text in column A

### Requirement 3: Preserve Visual Separation

**User Story:** As a manager, I want the exported Excel to still have visual separators between workplace groups, so that the data remains easy to scan visually.

#### Acceptance Criteria

1. THE Detail_Export SHALL retain Workplace_Header_Row rows as the first row of each workplace group
2. THE Detail_Export SHALL retain the 2 empty separator rows between workplace groups
3. THE Detail_Export SHALL apply bold styling to Workplace_Header_Row rows

### Requirement 4: Correct Column Formatting

**User Story:** As a manager, I want the time-based columns to retain their `[H]:mm` format and text columns to retain text formatting, so that the exported data displays correctly.

#### Acceptance Criteria

1. THE Detail_Export SHALL apply text format (`FORMAT_TEXT`) to column A (Xưởng) and column B (Ngày làm)
2. THE Detail_Export SHALL apply text format (`FORMAT_TEXT`) to column D (Mã nhân viên)
3. THE Detail_Export SHALL apply `[H]:mm` number format to columns I through P (time/hour columns)
4. THE Detail_Export SHALL apply bold styling to the header row (row 1, columns A through P)
5. THE Detail_Export SHALL apply thin borders to the entire data range (A1 through P{last_row})
