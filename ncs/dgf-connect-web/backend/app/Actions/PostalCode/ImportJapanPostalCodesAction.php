<?php

namespace App\Actions\PostalCode;

use App\Models\JapanPostalCode;
use App\Support\Address\JapaneseAddressNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Loads Japan Post's KEN_ALL file into `japan_postal_codes`. Rows are upserted by
 * their content hash and rows missing from the new file are pruned, all in one
 * transaction so readers never see a half-imported table.
 */
class ImportJapanPostalCodesAction
{
    private const CITY_DEFAULT_TOWN = '以下に掲載がない場合';

    private const BANCHI_FOLLOWS_CITY_SUFFIX = 'の次に番地がくる場合';

    private const WHOLE_AREA_SUFFIX = '一円';

    /**
     * @param ?string $file local .zip or .csv; downloaded from $url (or the configured URL) when null
     * @return array{batch: string, rows: int, deleted: int}
     */
    public function __invoke(?string $file = null, ?string $url = null): array
    {
        $temporary = [];
        try {
            $source = $file ?? $this->download($url ?? config('postal_codes.source_url'), $temporary);
            $csv = $this->csvPath($source, $temporary);

            return $this->import($csv);
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }

    private function download(string $url, array &$temporary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ken_all_');
        $temporary[] = $path;

        $response = Http::timeout((int) config('postal_codes.download_timeout'))
            ->withOptions(['sink' => $path])
            ->get($url);
        if (!$response->successful()) {
            throw new RuntimeException("Downloading {$url} failed with HTTP {$response->status()}");
        }

        return $path;
    }

    /**
     * Accepts the zip as published or an already extracted CSV.
     */
    private function csvPath(string $source, array &$temporary): string
    {
        $handle = fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$source}");
        }
        $magic = fread($handle, 2);
        fclose($handle);
        if ($magic !== 'PK') {
            return $source;
        }

        $zip = new ZipArchive();
        if ($zip->open($source) !== true) {
            throw new RuntimeException("Cannot open {$source} as a zip archive");
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || !str_ends_with(mb_strtolower($name), '.csv')) {
                    continue;
                }
                $path = tempnam(sys_get_temp_dir(), 'ken_all_csv_');
                $temporary[] = $path;
                $stream = $zip->getStream($name);
                if ($stream === false || file_put_contents($path, $stream) === false) {
                    throw new RuntimeException("Cannot extract {$name} from {$source}");
                }

                return $path;
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException("No CSV file found inside {$source}");
    }

    /**
     * @return array{batch: string, rows: int, deleted: int}
     */
    private function import(string $csv): array
    {
        $batch = Str::random(32);
        $chunkSize = max(1, (int) config('postal_codes.import_chunk_size'));
        $columns = [
            'postal_code',
            'prefecture',
            'city',
            'city_key',
            'town',
            'town_key',
            'town_detail',
            'is_city_default',
            'import_batch',
            'updated_at',
        ];

        return DB::transaction(function () use ($csv, $batch, $chunkSize, $columns) {
            $total = 0;
            $buffer = [];
            $seenHashes = [];
            foreach ($this->rows($csv) as $row) {
                // The file occasionally repeats a line verbatim; one upsert must not see it twice
                if (isset($seenHashes[$row['row_hash']])) {
                    continue;
                }
                $seenHashes[$row['row_hash']] = true;

                $buffer[] = $row + ['import_batch' => $batch, 'created_at' => now(), 'updated_at' => now()];
                if (count($buffer) >= $chunkSize) {
                    JapanPostalCode::query()->upsert($buffer, ['row_hash'], $columns);
                    $total += count($buffer);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                JapanPostalCode::query()->upsert($buffer, ['row_hash'], $columns);
                $total += count($buffer);
            }

            if ($total === 0) {
                throw new RuntimeException('The postal code file contained no rows; the table was left untouched');
            }

            $deleted = JapanPostalCode::query()->where('import_batch', '!=', $batch)->delete();

            return ['batch' => $batch, 'rows' => $total, 'deleted' => $deleted];
        });
    }

    /**
     * @return iterable<array<string, mixed>> table rows without the batch/timestamp columns
     */
    private function rows(string $csv): iterable
    {
        $handle = fopen($csv, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$csv}");
        }

        try {
            $pending = null;
            while (($line = fgets($handle)) !== false) {
                $line = mb_rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                // The legacy download is Shift_JIS; the UTF-8 variant is preferred but both work
                if (!mb_check_encoding($line, 'UTF-8')) {
                    $line = mb_convert_encoding($line, 'UTF-8', 'SJIS-win');
                }
                $fields = str_getcsv($line, ',', '"', '');
                if (count($fields) < 9) {
                    continue;
                }

                $record = [
                    'postal_code' => mb_trim($fields[2]),
                    'prefecture' => mb_trim($fields[6]),
                    'city' => mb_trim($fields[7]),
                    'town' => mb_trim($fields[8]),
                ];
                if (!preg_match('/^\d{7}$/', $record['postal_code'])) {
                    continue;
                }

                // A town whose parenthetical is too long is continued on the following
                // line(s) with the same postal code; stitch those back together.
                if ($pending !== null) {
                    $pending['town'] .= $record['town'];
                    $record = $pending;
                    $pending = null;
                }
                if (str_contains($record['town'], '（') && !str_contains($record['town'], '）')) {
                    $pending = $record;

                    continue;
                }

                yield $this->buildRow($record);
            }

            if ($pending !== null) {
                throw new RuntimeException(
                    "{$csv} ends inside a wrapped town qualifier ({$pending['postal_code']} {$pending['town']}); the file is probably truncated"
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{postal_code: string, prefecture: string, city: string, town: string} $record
     */
    private function buildRow(array $record): array
    {
        [$town, $detail, $isCityDefault] = $this->splitTown($record['town'], $record['city']);

        return [
            'postal_code' => $record['postal_code'],
            'prefecture' => $record['prefecture'],
            'city' => $record['city'],
            'city_key' => JapaneseAddressNormalizer::normalize($record['city']),
            'town' => $town,
            'town_key' => JapaneseAddressNormalizer::normalize(JapaneseAddressNormalizer::stripAzaPrefix($town)),
            'town_detail' => $detail,
            'is_city_default' => $isCityDefault,
            'row_hash' => hash(
                'sha256',
                implode('|', [
                    $record['postal_code'],
                    $record['prefecture'],
                    $record['city'],
                    $town,
                    $detail ?? '',
                    $isCityDefault ? '1' : '0',
                ])
            ),
        ];
    }

    /**
     * @return array{0: string, 1: ?string, 2: bool} town, detail, is_city_default
     */
    private function splitTown(string $rawTown, string $city): array
    {
        if ($rawTown === self::CITY_DEFAULT_TOWN) {
            return ['', null, true];
        }

        // "坂戸市の次に番地がくる場合": the 番地 follows the city name directly
        if (str_ends_with($rawTown, self::BANCHI_FOLLOWS_CITY_SUFFIX)) {
            return ['', $rawTown, false];
        }

        // "利島村一円": the whole municipality shares one code. A handful of real towns are
        // literally named 一円 (犬上郡多賀町), so only treat it as city-level when the prefix
        // is the municipality itself.
        if (str_ends_with($rawTown, self::WHOLE_AREA_SUFFIX)) {
            $area = mb_substr($rawTown, 0, -mb_strlen(self::WHOLE_AREA_SUFFIX));
            if ($area !== '' && str_ends_with($city, $area)) {
                return ['', $rawTown, false];
            }
        }

        if (preg_match('/^(.*?)（(.*)）$/u', $rawTown, $m)) {
            return [$m[1], $m[2] === '' ? null : $m[2], false];
        }

        return [$rawTown, null, false];
    }
}
