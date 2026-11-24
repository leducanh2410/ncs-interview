<?php

return [
    'max_zip_size_bytes' => env('PDF_EXPORT_MAX_ZIP_SIZE_BYTES', 500 * 1024 * 1024),
    'max_zip_file_count' => env('PDF_EXPORT_MAX_ZIP_FILE_COUNT', 250),
    'chunk_size' => env('PDF_EXPORT_CHUNK_SIZE', 50),
    'excel_max_rows' => env('PDF_EXPORT_EXCEL_MAX_ROWS', 5000),
    'excel_max_file_size_kb' => env('PDF_EXPORT_EXCEL_MAX_FILE_SIZE_KB', 5120),
    'excel_meta_row_limit' => env('PDF_EXPORT_EXCEL_META_ROW_LIMIT', 500),
];
