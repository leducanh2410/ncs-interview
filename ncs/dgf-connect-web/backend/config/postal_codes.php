<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Japan Post KEN_ALL source
    |--------------------------------------------------------------------------
    |
    | The UTF-8 "KEN_ALL" master file published by Japan Post. It is refreshed at
    | the end of every month; `postal-codes:import` downloads it from this URL
    | unless a local file is passed with `--file`.
    |
    */
    'source_url' => env(
        'JAPAN_POSTAL_CODES_URL',
        'https://www.post.japanpost.jp/service/search/zipcode/download/utf/zip/utf_ken_all.zip'
    ),

    'download_timeout' => (int) env('JAPAN_POSTAL_CODES_DOWNLOAD_TIMEOUT', 120),

    // Rows written per upsert statement during the import
    'import_chunk_size' => (int) env('JAPAN_POSTAL_CODES_IMPORT_CHUNK', 1000),
];
