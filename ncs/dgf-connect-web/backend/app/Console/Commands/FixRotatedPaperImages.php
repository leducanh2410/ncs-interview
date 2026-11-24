<?php

namespace App\Console\Commands;

use App\Actions\Ocr\FixPaperImageRotationAction;
use App\Actions\Ocr\RestartInvalidatedDocumentOcrAction;
use App\Enums\UserPaperType;
use App\Models\UserPaperItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class FixRotatedPaperImages extends Command
{
    private const DEFAULT_TYPES = [
        UserPaperType::Passport,
        UserPaperType::ForeignerCardFront,
        UserPaperType::ForeignerCardBack,
        UserPaperType::ForeignerCardScan,
        UserPaperType::StudentCardFront,
        UserPaperType::StudentCardBack,
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'papers:fix-rotation
        {from : ID of the first user_paper_items row to scan}
        {to : ID of the last user_paper_items row to scan (inclusive)}
        {--dry-run : Detect rotation only; do not modify any file}
        {--sleep=1 : Seconds to wait between images, to stay under provider rate limits}
        {--types= : Comma-separated paper types to scan (defaults to the OCR-relevant types)}
        {--force : Do not ask for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect rotated paper images with an LLM and rewrite them upright, one image at a time';

    public function handle(
        FixPaperImageRotationAction $fixRotation,
        RestartInvalidatedDocumentOcrAction $restartInvalidatedOcr
    ): int {
        $from = (int) $this->argument('from');
        $to = (int) $this->argument('to');
        if ($from <= 0 || $to < $from) {
            $this->error('Invalid id range: from must be positive and to must be >= from.');

            return self::FAILURE;
        }

        $types = $this->resolveTypes();
        if ($types === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sleepSeconds = max(0, (float) $this->option('sleep'));

        $items = UserPaperItem::query()
            ->whereBetween('id', [$from, $to])
            ->whereIn('type', $types)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            $this->info('No paper images found in the given range.');

            return self::SUCCESS;
        }

        $this->info(
            sprintf(
                '%s %d image(s) (ids %d-%d, types: %s)',
                $dryRun ? 'Checking' : 'Checking and fixing',
                $items->count(),
                $from,
                $to,
                implode(', ', $types)
            )
        );

        if (
            !$dryRun &&
            !$this->option('force') &&
            !$this->confirm('Rotated images will be overwritten (originals are backed up). Continue?')
        ) {
            return self::SUCCESS;
        }

        $rotated = 0;
        $upright = 0;
        $failed = 0;
        $processedItemIds = [];
        $failedItemIds = [];

        foreach ($items as $index => $item) {
            if ($index > 0 && $sleepSeconds > 0) {
                usleep((int) ($sleepSeconds * 1_000_000));
            }

            try {
                $result = $fixRotation($item, $dryRun);
            } catch (Throwable $e) {
                $failed++;
                $failedItemIds[] = $item->id;
                $this->warn(sprintf('#%d %s: FAILED (%s)', $item->id, $item->file_path, $e->getMessage()));
                Log::error('Paper rotation fix failed', [
                    'item_id' => $item->id,
                    'file_path' => $item->file_path,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $processedItemIds[] = $item->id;

            if ($result['rotation'] === 0) {
                $upright++;
                $this->line(sprintf('#%d %s: already upright', $item->id, $item->file_path));
            } elseif ($dryRun) {
                $rotated++;
                $this->info(
                    sprintf(
                        '#%d %s: rotated %d° (dry run, not fixed)',
                        $item->id,
                        $item->file_path,
                        $result['rotation']
                    )
                );
            } else {
                $rotated++;
                $this->info(
                    sprintf(
                        '#%d %s: rotated %d° -> fixed (backup: %s, %d cached OCR result(s) invalidated)',
                        $item->id,
                        $item->file_path,
                        $result['rotation'],
                        $result['backup_path'],
                        $result['invalidated_results']
                    )
                );
            }
        }

        if (!$dryRun) {
            // Restart only after every image in the range has been processed. A document step
            // can contain several images, and dispatching after each one wastes OCR calls on
            // partially fixed input. Upright items recover interrupted runs, while a failed item
            // prevents its step from restarting until a later successful retry.
            $restartInvalidatedOcr($processedItemIds, $failedItemIds);
        }

        $this->newLine();
        $this->table(
            ['Scanned', $dryRun ? 'Rotated (detected)' : 'Rotated (fixed)', 'Already upright', 'Failed'],
            [[$items->count(), $rotated, $upright, $failed]]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return string[]|null null when an unknown type was requested
     */
    private function resolveTypes(): ?array
    {
        $option = mb_trim((string) $this->option('types'));
        if ($option === '') {
            return array_map(fn(UserPaperType $type) => $type->value, self::DEFAULT_TYPES);
        }

        $types = array_values(array_filter(array_map('trim', explode(',', $option))));
        $known = UserPaperType::values();
        $unknown = array_diff($types, $known);
        if (!empty($unknown)) {
            $this->error(
                'Unknown paper type(s): ' . implode(', ', $unknown) . '. Known types: ' . implode(', ', $known)
            );

            return null;
        }

        return $types;
    }
}
