<?php

namespace App\Console\Commands;

use App\Imports\QuestionSetImport;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ImportQuestions extends Command
{
    protected $signature = 'app:import-questions {path}';

    protected $description = 'Import questions from an XLSX file';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found at: $path");

            return;
        }

        $this->info('Starting import...');
        // ini_set('memory_limit', '128M');
        Excel::import(new QuestionSetImport($this), $path);
        $this->info('Import successful!');
    }
}
