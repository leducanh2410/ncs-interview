<?php

namespace App\Console\Commands;

use App\Actions\PostalCode\ImportJapanPostalCodesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportJapanPostalCodes extends Command
{
    protected $signature = 'postal-codes:import
        {--file= : Local KEN_ALL .zip or .csv instead of downloading}
        {--url= : Download URL, defaults to config postal_codes.source_url}';

    protected $description = 'Import (or refresh) the Japan Post KEN_ALL postal code table';

    public function handle(ImportJapanPostalCodesAction $import): int
    {
        $file = $this->option('file');
        $url = $this->option('url');
        $source = $file ?: ($url ?: config('postal_codes.source_url'));

        $this->info($file ? "Importing postal codes from {$file}" : "Downloading postal codes from {$source}");

        // The scheduler discards console output, so the log is the only trace of the monthly refresh
        try {
            $result = $import($file ?: null, $url ?: null);
        } catch (Throwable $e) {
            Log::error('Japan Post postal code import failed', ['source' => $source, 'exception' => $e]);
            $this->error('Import failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        Log::info('Japan Post postal code import finished', ['source' => $source] + $result);
        $this->info(
            "Imported {$result['rows']} rows, removed {$result['deleted']} stale rows (batch {$result['batch']})"
        );

        return self::SUCCESS;
    }
}
