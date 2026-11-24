<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Exports\ReconciliationWorkHistoryExport;
use App\Models\CheckIn;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationExportEligibility;
use App\Services\Reconciliation\ReconciliationFieldCompare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;
use ZipArchive;

class ExportReconciliationBatchAction
{
    public function __construct(
        private readonly OverlayReconciliationExportDataAction $overlay,
        private readonly ApplyReconciliationMatchAction $applyMatch
    ) {}

    public function __invoke(User $actor, ReconciliationBatch $batch, bool $storeOnly = false): ActionResult
    {
        return DB::transaction(function () use ($actor, $batch, $storeOnly) {
            $batch = ReconciliationBatch::lockById($batch->id);
            if (!$batch->isConfirmed()) {
                return new ActionResult(error: ErrorCode::ReconciliationBatchNotConfirmed, status: 409);
            }

            $rows = $batch
                ->rows()
                ->with(['checkIn.shift.workplace', 'checkIn.shift.workplaceShift', 'checkIn.user', 'checkIn.shiftUser'])
                ->get();
            $pendingCheckInIds = $this->applyMatch->pendingCheckInIds(
                $rows->pluck('check_in_id')->filter()->unique()->values()->all()
            );
            $exportable = [];
            // Collected rather than returned on the first hit: the admin fixes
            // these one screen at a time, and reporting them one per request
            // would mean a Confirm→Export round trip for every affected row.
            $staleRowIds = [];
            foreach ($rows as $row) {
                if (!ReconciliationExportEligibility::shouldExport($row)) {
                    continue;
                }
                $checkIn = $row->checkIn;
                // `reconciliation_rows.check_in_id` is `nullOnDelete()` and
                // CheckIn has no SoftDeletes, so a check-in hard-deleted after
                // Confirm (shift re-planning, the user dropped from the shift)
                // empties this column while the row keeps the Amazon decisions
                // the admin made. That is the most extreme form of "DGF changed
                // since the snapshot" (Requirement 11.2), and the detail screen
                // renders the row from `snapshot_dgf_*` so it still looks
                // perfectly normal — leaving it out of the workbook would drop
                // confirmed data with no signal at all. Unlike a drifted row it
                // can never be repaired (the id is gone for good), which is why
                // the detail screen offers to skip exactly these rows — the
                // same pair of conditions that got us here, a null
                // `check_in_id` on a row ReconciliationExportEligibility says
                // belongs in the workbook, is what the serializer reports as
                // `can_skip` and what the PATCH accepts (see
                // UpdateReconciliationRowAction for the write side).
                if ($checkIn === null) {
                    $staleRowIds[] = $row->id;

                    continue;
                }
                if (isset($pendingCheckInIds[$checkIn->id])) {
                    continue;
                }
                if ($this->isStale($row, $checkIn)) {
                    $staleRowIds[] = $row->id;

                    continue;
                }
                $item = $this->overlay->overlay($this->overlay->mapCheckIn($checkIn), $row, $checkIn);
                $exportable[$checkIn->shift->workplace_id][] = $item;
            }

            if ($staleRowIds !== []) {
                return new ActionResult(
                    error: ErrorCode::ReconciliationStale,
                    status: 409,
                    data: ['row_ids' => $staleRowIds]
                );
            }

            if ($exportable === []) {
                return new ActionResult(error: ErrorCode::ReconciliationNothingToExport, status: 422);
            }

            $path =
                count($exportable) === 1 ? $this->writeXlsx($batch, $exportable) : $this->writeZip($batch, $exportable);

            $batch->export_file_path = $path;
            $batch->exported_by = $actor->id;
            $batch->exported_at = now();
            $batch->save();

            if ($storeOnly) {
                return new ActionResult(
                    data: [
                        'filename' => $batch->exportDownloadName(),
                        'content_type' => str_ends_with($path, '.zip')
                            ? 'application/zip'
                            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]
                );
            }

            return new ActionResult(
                data: [
                    'path' => $path,
                    'filename' => $batch->exportDownloadName(),
                ]
            );
        });
    }

    private function isStale(ReconciliationRow $row, CheckIn $checkIn): bool
    {
        $liveIn = $checkIn->check_in_time ? Carbon::parse($checkIn->check_in_time) : null;
        $snapIn = $row->snapshot_dgf_check_in ? Carbon::parse($row->snapshot_dgf_check_in) : null;
        if ($liveIn?->toDateTimeString() !== $snapIn?->toDateTimeString()) {
            return true;
        }
        $liveOut = $checkIn->check_out_time ? Carbon::parse($checkIn->check_out_time) : null;
        $snapOut = $row->snapshot_dgf_check_out ? Carbon::parse($row->snapshot_dgf_check_out) : null;
        if ($liveOut?->toDateTimeString() !== $snapOut?->toDateTimeString()) {
            return true;
        }

        if ((int) $checkIn->break_minutes !== (int) $row->snapshot_break_column) {
            return true;
        }

        return $row->snapshot_dgf_paid !== null && $this->paidDrifted($row->snapshot_dgf_paid, $checkIn);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function paidDrifted(array $snapshot, CheckIn $checkIn): bool
    {
        $live = ReconciliationFieldCompare::paidSnapshot($checkIn);
        if (
            !ReconciliationFieldCompare::timesMatch($snapshot['check_in'] ?? null, $live['check_in']) ||
            !ReconciliationFieldCompare::timesMatch($snapshot['check_out'] ?? null, $live['check_out'])
        ) {
            return true;
        }

        foreach (ReconciliationFieldCompare::SUMMARY_FIELDS as $field) {
            $snapped = $snapshot[$field] ?? null;
            if (($snapped === null ? null : (int) $snapped) !== $live[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $exportable
     */
    private function writeXlsx(ReconciliationBatch $batch, array $exportable): string
    {
        $relative = 'reconciliation/' . $batch->id . '/export.xlsx';
        $staging = $relative . '.writing';

        // `finally` rather than a cleanup on each error branch: Excel::store()
        // can throw as well as return false, and a staging file left behind on
        // that path would sit on disk until the next export overwrites it.
        // Deleting after a successful promote() is a no-op, the file has moved.
        try {
            // The `local` disk runs with `throw => false`, so a failed write (no
            // space left, an unwritable reconciliation/{id} directory) comes
            // back as `false` rather than as an exception. Ignoring it would
            // stamp export_file_path/exported_by/at and then hand the admin a
            // 404 on download.
            if (
                Excel::store(new ReconciliationWorkHistoryExport($exportable), $staging, null, ExcelFormat::XLSX) ===
                false
            ) {
                throw new RuntimeException('Failed to store the reconciliation export workbook.');
            }

            return $this->promote($staging, $relative);
        } finally {
            Storage::delete($staging);
        }
    }

    /**
     * @param array<int, list<array<string, mixed>>> $exportable
     */
    private function writeZip(ReconciliationBatch $batch, array $exportable): string
    {
        $relative = 'reconciliation/' . $batch->id . '/export.zip';
        $staging = $relative . '.writing';
        $absolute = Storage::path($staging);
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create reconciliation export zip.');
        }

        // Every exit from here on — returned `false`, or an exception thrown by
        // Excel::raw() building one workplace's sheet — has to close the archive
        // handle and drop the staging file, or a half-written
        // `export.zip.writing` is left on disk. `$closed` guards the second
        // close(): ZipArchive::close() clears the handle even when it returns
        // false, so calling it again raises a ValueError that `@` cannot
        // suppress and that would replace the real cause (typically a full disk,
        // which ZIP only reports at close() time since that is when it flushes).
        $closed = false;
        try {
            $used = [];
            foreach ($exportable as $workplaceId => $rows) {
                $name = $rows[0]['workplace_name'] ?? "workplace_{$workplaceId}";
                $safe = mb_trim(preg_replace('/[\\/\\\\:*?"<>|]+/', '_', $name) ?? $name);
                if ($safe === '' || isset($used[$safe])) {
                    $safe = "{$safe}_{$workplaceId}";
                }
                $used[$safe] = true;
                $content = Excel::raw(new ReconciliationWorkHistoryExport([$workplaceId => $rows]), ExcelFormat::XLSX);
                // Same reasoning as writeXlsx(): a per-entry or finalise failure
                // is reported as `false`, and swallowing it would produce a
                // truncated archive recorded as a successful export.
                if (!$zip->addFromString("{$safe}.xlsx", $content)) {
                    throw new RuntimeException("Failed to add {$safe}.xlsx to the reconciliation export zip.");
                }
            }
            $finalised = $zip->close();
            $closed = true;
            if (!$finalised) {
                throw new RuntimeException('Failed to finalise the reconciliation export zip.');
            }

            return $this->promote($staging, $relative);
        } catch (Throwable $exception) {
            if (!$closed) {
                try {
                    $zip->close();
                } catch (Throwable) {
                    // Releasing the handle is best-effort cleanup; the failure
                    // that got us here is the one worth reporting.
                }
            }

            throw $exception;
        } finally {
            Storage::delete($staging);
        }
    }

    /**
     * Both writers build into a `.writing` staging path and only take over the
     * real one once the file is complete. A re-export that fails halfway would
     * otherwise leave a truncated artifact at the path the batch already points
     * at: the RuntimeException rolls the DB back to the *previous* successful
     * export — same path, same audit columns — while downloadExport() streams
     * the corrupt bytes that overwrote it.
     */
    private function promote(string $staging, string $relative): string
    {
        // Not preceded by a delete of $relative on purpose: a failed move must
        // leave the previous export in place, and the local disk's rename
        // overwrites it anyway once the move does succeed. Cleaning up the
        // staging file is left to both callers' `finally`.
        if (!Storage::move($staging, $relative)) {
            throw new RuntimeException('Failed to publish the reconciliation export file.');
        }

        return $relative;
    }
}
