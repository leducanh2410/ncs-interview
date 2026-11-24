<?php

namespace App\Services\Reconciliation;

use App\Enums\ReconciliationDecision;
use App\Models\ReconciliationRow;

/**
 * The single answer to "would this row end up in the workbook?".
 *
 * Export, the confirmed-batch skip escape hatch and the detail screen all have
 * to agree on it: when they don't, the screen offers to skip rows the export
 * never looked at, and the API accepts a write on a frozen batch that changes
 * nothing an admin can observe.
 */
class ReconciliationExportEligibility
{
    /**
     * A row belongs in the workbook only when an Amazon decision would actually
     * change a DGF value (Requirement 10.2/10.5). "Amazon on a field that
     * already agrees with DGF" is reachable — PATCH `decisions` accepts it on a
     * matched field, and Confirm only recomputes a field whose DGF value
     * drifted — and exporting such a row hands the importer a no-change line.
     * The comparison uses `snapshot_dgf_*`, the DGF values the decision was
     * made against, which is also what the export's staleness check defends.
     */
    public static function shouldExport(ReconciliationRow $row): bool
    {
        return !$row->skipped && self::hasAcceptedAmazonChange($row);
    }

    /**
     * shouldExport() without the `skipped` gate: "this row carries an accepted
     * Amazon change", which stays true after the admin skips it. Both the
     * serialized `can_skip` and the confirmed-batch PATCH use this rather than
     * shouldExport(), so the skip control does not vanish the moment it is used
     * and a repeated skip stays idempotent instead of coming back 409.
     */
    public static function hasAcceptedAmazonChange(ReconciliationRow $row): bool
    {
        $amazon = ReconciliationDecision::Amazon->value;

        $paid = $row->snapshot_dgf_paid;
        $snapshotCheckIn =
            $paid !== null ? $paid['check_in'] ?? null : optional($row->snapshot_dgf_check_in)->toDateTimeString();
        $snapshotCheckOut =
            $paid !== null ? $paid['check_out'] ?? null : optional($row->snapshot_dgf_check_out)->toDateTimeString();

        if (
            $row->decision_check_in === $amazon &&
            !ReconciliationFieldCompare::timesMatch(
                $snapshotCheckIn,
                optional($row->external_check_in)->toDateTimeString()
            )
        ) {
            return true;
        }
        if (
            $row->decision_check_out === $amazon &&
            !ReconciliationFieldCompare::timesMatch(
                $snapshotCheckOut,
                optional($row->external_check_out)->toDateTimeString()
            )
        ) {
            return true;
        }

        return $row->decision_break === $amazon &&
            ($row->snapshot_effective_break === null ||
                (int) $row->external_break_minutes !== (int) $row->snapshot_effective_break);
    }
}
