<?php

namespace Tests\Concerns;

use App\Actions\PostalCode\ImportJapanPostalCodesAction;
use ZipArchive;

/**
 * A slice of Japan Post's KEN_ALL file covering every shape the resolver has to
 * handle, loaded through the real import so tests exercise the same path as
 * production data.
 */
trait SeedsJapanPostalCodes
{
    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}> postal code, prefecture, city, town
     */
    protected function postalCodeFixtureRows(): array
    {
        return [
            // Same town name in two cities of the same prefecture
            ['3500067', '埼玉県', '川越市', '三光町'],
            ['3500235', '埼玉県', '坂戸市', '三光町'],
            ['3500200', '埼玉県', '坂戸市', '以下に掲載がない場合'],
            ['3500201', '埼玉県', '坂戸市', '赤尾'],
            // 郡 + 町 municipality
            ['3500321', '埼玉県', '比企郡鳩山町', '赤沼'],
            // 東京都府中市 contains the substring 京都府
            ['1830022', '東京都', '府中市', '宮西町'],
            ['1830000', '東京都', '府中市', '以下に掲載がない場合'],
            ['6040000', '京都府', '京都市中京区', '以下に掲載がない場合'],
            // Town split by 丁目 ranges
            ['0600042', '北海道', '札幌市中央区', '大通西（１〜１９丁目）'],
            ['0640820', '北海道', '札幌市中央区', '大通西（２０〜２８丁目）'],
            // Town split by 番地 with a catch-all
            ['0050863', '北海道', '札幌市南区', '常盤（その他）'],
            ['0050865', '北海道', '札幌市南区', '常盤（１〜１３１番地）'],
            // Town with a plain row plus building-specific rows
            ['1000005', '東京都', '千代田区', '丸の内（次のビルを除く）'],
            ['1006601', '東京都', '千代田区', '丸の内グラントウキョウサウスタワー（１階）'],
            ['1006602', '東京都', '千代田区', '丸の内グラントウキョウサウスタワー（２階）'],
            ['1600023', '東京都', '新宿区', '西新宿（次のビルを除く）'],
            // Whole municipality under one code, and a town literally named 一円
            ['1000301', '東京都', '利島村', '利島村一円'],
            ['5220317', '滋賀県', '犬上郡多賀町', '一円'],
            // 番地 directly after the city name, in a town that still lists ordinary 大字
            ['3060433', '茨城県', '猿島郡境町', '境町の次に番地がくる場合'],
            ['3060400', '茨城県', '猿島郡境町', '以下に掲載がない場合'],
            ['3060404', '茨城県', '猿島郡境町', '伏木'],
            // 番地 split on a sub-number
            ['0682181', '北海道', '美唄市', '光珠内町南（２７９４−１２番地）'],
            ['0790167', '北海道', '美唄市', '光珠内町南（その他）'],
            // Split by a unit the resolver does not read
            ['0770013', '北海道', '留萌市', '留萌原野（１〜１２線）'],
            ['0783165', '北海道', '留萌市', '留萌原野（その他）'],
            // Kana variant
            ['0861153', '北海道', '標津郡中標津町', '桜ヶ丘'],
            // 丁目 split without a catch-all, so an address without 丁目 stays ambiguous
            ['5300001', '大阪府', '大阪市北区', '梅田（１丁目）'],
            ['5300002', '大阪府', '大阪市北区', '梅田（２、３丁目）'],
            // Qualifiers that all lead to the same code
            ['1000006', '東京都', '千代田区', '有楽町（１丁目）'],
            ['1000006', '東京都', '千代田区', '有楽町（２丁目）'],
        ];
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}>|null $rows
     * @return array{batch: string, rows: int, deleted: int}
     */
    protected function seedJapanPostalCodes(?array $rows = null): array
    {
        $csv = $this->writePostalCodeCsv($rows ?? $this->postalCodeFixtureRows());
        try {
            return app(ImportJapanPostalCodesAction::class)($csv);
        } finally {
            @unlink($csv);
        }
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows
     * @return string path of a temporary CSV in KEN_ALL layout
     */
    protected function writePostalCodeCsv(array $rows, string $lineEnding = "\r\n"): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ken_all_test_');
        file_put_contents($path, $this->postalCodeCsvContent($rows, $lineEnding));

        return $path;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows
     * @return string path of a temporary zip holding the CSV, as Japan Post publishes it
     */
    protected function writePostalCodeZip(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ken_all_test_zip_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('utf_ken_all.csv', $this->postalCodeCsvContent($rows));
        $zip->close();

        return $path;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows
     */
    protected function postalCodeCsvContent(array $rows, string $lineEnding = "\r\n"): string
    {
        $lines = array_map(
            fn(array $row) => sprintf(
                '01101,"%s  ","%s","カナ","カナ","カナ","%s","%s","%s",0,0,0,0,0,0',
                mb_substr($row[0], 0, 3),
                $row[0],
                $row[1],
                $row[2],
                $row[3]
            ),
            $rows
        );

        return implode($lineEnding, $lines) . $lineEnding;
    }
}
