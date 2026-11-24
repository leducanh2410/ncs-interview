<?php

namespace App\Helpers;

use App\Enums\Encoding;
use Illuminate\Support\Collection;
use League\Csv\Exception;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\SyntaxError;
use League\Csv\UnavailableStream;

class CsvHelper
{
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function escapeFormula(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], self::FORMULA_TRIGGERS, true) ? "'" . $value : $value;
    }

    /**
     * Parse a CSV file (supports Shift JIS encoding).
     *
     * @param string $path       Full path to the CSV file.
     * @param string $delimiter  CSV delimiter (default: ',').
     * @param string $enclosure  CSV enclosure (default: '"').
     * @param string $encoding   Original file encoding (default: 'SJIS-win').
     * @return Collection
     *
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws Exception
     */
    public static function parse(
        string $path,
        string $encoding,
        string $delimiter = ',',
        string $enclosure = '"'
    ): Collection {
        if (!is_readable($path)) {
            return collect();
        }

        $encoding = match ($encoding) {
            Encoding::UTF8->value => 'UTF-8',
            Encoding::ShiftJIS->value => 'SJIS-win',
        };

        // Read file content and convert encoding to UTF-8
        $content = file_get_contents($path);
        $utf8Content = mb_convert_encoding($content, 'UTF-8', $encoding);

        // Create CSV Reader from string
        $csv = Reader::createFromString($utf8Content);
        $csv->setDelimiter($delimiter);
        $csv->setEnclosure($enclosure);
        $csv->setHeaderOffset(null);

        $stmt = new Statement();
        $records = $stmt->process($csv);

        return collect($records->getRecords());
    }
}
