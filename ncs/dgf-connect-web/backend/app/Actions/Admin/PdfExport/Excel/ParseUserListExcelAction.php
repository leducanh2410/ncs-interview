<?php

namespace App\Actions\Admin\PdfExport\Excel;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Imports\UserListExcelImport;
use App\ValueObjects\PdfExport\ExcelUserRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Throwable;

class ParseUserListExcelAction
{
    private const STAFF_NO_COLUMN = 0;

    private const NAME_COLUMN = 1;

    private const JAPANESE_CSV_ENCODING = 'CP932';

    private const STAFF_NO_HEADER_KEYWORDS = [
        'no',
        'number',
        'id',
        'code',
        'staffno',
        'staffnumber',
        'staffid',
        'staffcode',
        'employeeno',
        'employeenumber',
        'employeeid',
        'employeecode',
        'stt',
        'mãnv',
        'manv',
        'msnv',
        'mãsố',
        'maso',
        'mãnhânviên',
        'manhanvien',
        'mãsốnhânviên',
        'masonhanvien',
        '社員no',
        '社員id',
        '社員コード',
        '従業員no',
        '従業員id',
        '従業員コード',
        '職員no',
    ];

    private const NAME_HEADER_KEYWORDS = [
        'name',
        'fullname',
        'staffname',
        'employeename',
        'username',
        'tên',
        'ten',
        'họtên',
        'hoten',
        'họvàtên',
        'hovaten',
        'tênnhânviên',
        'tennhanvien',
        '氏名',
        '名前',
        '社員名',
        '従業員名',
        '職員名',
    ];

    public function __invoke(UploadedFile $file): ActionResult
    {
        try {
            $import = new UserListExcelImport($this->detectCsvEncoding($file));
            Excel::import($import, $file);
        } catch (Throwable) {
            return new ActionResult(error: ErrorCode::PdfExportExcelInvalidFormat, status: 422);
        }

        $rows = $this->toUserRows($import->rows());

        if ($rows === []) {
            return new ActionResult(error: ErrorCode::PdfExportExcelEmpty, status: 422);
        }

        $maxRows = max((int) config('pdf_export.excel_max_rows', 5000), 1);

        if (count($rows) > $maxRows) {
            return new ActionResult(
                error: ErrorCode::PdfExportExcelTooManyRows,
                status: 422,
                data: ['max_rows' => $maxRows, 'row_count' => count($rows)]
            );
        }

        return new ActionResult(data: $rows);
    }

    private function detectCsvEncoding(UploadedFile $file): string
    {
        if (mb_strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return Csv::GUESS_ENCODING;
        }

        $path = $file->getRealPath();
        $encoding = Csv::guessEncoding($path, '');

        if ($encoding !== '') {
            return $encoding;
        }

        $contents = (string) file_get_contents($path);

        return mb_check_encoding($contents, self::JAPANESE_CSV_ENCODING)
            ? self::JAPANESE_CSV_ENCODING
            : Csv::DEFAULT_FALLBACK_ENCODING;
    }

    /**
     * @param Collection<int, Collection<int, mixed>> $sheetRows
     * @return array<int, ExcelUserRow>
     */
    private function toUserRows(Collection $sheetRows): array
    {
        $rows = [];
        $beforeFirstDataRow = true;

        foreach ($sheetRows->values() as $index => $cells) {
            $rowNumber = $index + 1;
            $staffNo = $this->normalizeStaffNo($cells->get(self::STAFF_NO_COLUMN));
            $name = $this->normalizeName($cells->get(self::NAME_COLUMN));

            if ($staffNo === null && $name === null) {
                continue;
            }

            if ($beforeFirstDataRow && $this->isTitleOrHeaderRow($staffNo, $name)) {
                continue;
            }

            $beforeFirstDataRow = false;

            $rows[] = new ExcelUserRow($rowNumber, $staffNo ?? '', $name);
        }

        return $rows;
    }

    private function isTitleOrHeaderRow(?string $staffNo, ?string $name): bool
    {
        if ($staffNo === null) {
            return $name !== null && $this->isHeaderKeyword($name, self::NAME_HEADER_KEYWORDS);
        }

        return preg_match('/[A-Za-z0-9]/', $staffNo) !== 1 ||
            $this->isHeaderKeyword($staffNo, self::STAFF_NO_HEADER_KEYWORDS);
    }

    /**
     * @param array<int, string> $keywords
     */
    private function isHeaderKeyword(string $value, array $keywords): bool
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(mb_convert_kana($value, 'asKV')));

        return in_array($normalized, $keywords, true);
    }

    private function normalizeStaffNo(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        $normalized = mb_trim(mb_convert_kana((string) $value, 'as'));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_trim(preg_replace('/\s+/u', ' ', mb_convert_kana((string) $value, 's')) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
