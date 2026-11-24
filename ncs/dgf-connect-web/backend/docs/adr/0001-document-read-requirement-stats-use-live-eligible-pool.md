# Document Read Requirement stats use the live Eligible Pool

For confirmation-rate stats on a Document Read Requirement, we compute the denominator (and intersect the numerator) against the **current Eligible Pool**, recomputed on every read, rather than persisting a snapshot of recipients at Backfill time. Reason: the admin's question behind these stats is "who do I still need to chase," which requires today's truth — frozen snapshots show departed employees as still-pending and miss new joiners that the per-user listener pushed to after Backfill ran. Both numerator (Confirmed) and denominator (Eligible) come from the same live set, so the rate stays coherent (rate ≤ 100%, pending ≥ 0).

## Considered Options

- **Frozen snapshot at Backfill time** — rejected: only captures one of three send moments (admin Backfill, `UserPaperApproved` listener, workplace-shift-approval listener); becomes stale on staff changes.
- **Cumulative send log** (new `document_required_config_sends` table) — rejected: solves a different problem (audit trail of pushes) and requires write hooks in three places. Worth revisiting only if compliance ever needs "prove we notified employee X on date Y."
- **Live Eligible Pool (chosen)** — reuses the existing `DocumentReadConfigService::getBackfillCount` logic; no new persistence.

## Consequences

- The stats panel's Confirmed count can be lower than the row count in the confirmations table on the same page. The table shows all historical Read Confirmations; the stats panel intersects with the current Eligible Pool. This divergence is expected, not a bug.
- Stats are computed per request and not cached. If the Eligible Pool query becomes a hotspot, cache per `(config_id, short TTL)` — do not introduce a persisted snapshot just to avoid the recomputation.
