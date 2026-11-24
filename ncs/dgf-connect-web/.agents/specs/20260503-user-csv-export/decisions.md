# Design Decisions Log

This document records the Q&A exchanges that shaped the spec for the User CSV Export feature, captured during the spec-creator clarification phase on 2026-05-03. Each decision references the requirements/design choices it influences.

## Q1: Processing model

**Question:** How should CSV export be processed end-to-end?

**Options considered:**
- Reuse async batch (same table) — add `type` column to existing `ExportBatch`, run through same queue, unified history with type filter.
- Reuse async batch (separate table) — new `CsvExportBatch` table mirroring `ExportBatch`, async job, unified history page.
- Synchronous direct download — generate in-request, stream to browser; only large exports queued.

**Decision:** Reuse async batch (same table).

**Rationale:** Minimizes new tables and infra. Single `ExportBatch` row drives both PDF and CSV; unified history is a natural consequence. Async-by-default avoids the corner case of large user sets blocking the request.

**Affects:** Requirement 6 (async processing), Requirement 7 (unified history), Design — single `ExportBatch` table with `type` column.

---

## Q2: Property selection modal layout

**Question:** How should the property selection modal organize fields?

**Options considered:**
- Grouped by section (mirrors user_form.tsx sections, drag across groups).
- Flat reorderable list (single drag-and-drop list, all fields).
- Two-pane available vs selected (left = unselected, right = selected, drag-to-reorder right pane).

**Decision:** Flat reorderable list.

**Rationale:** Simplest interaction model; users can express any order without group constraints. Drag handle + checkbox per row makes the affordance obvious.

**Affects:** Requirement 2 (property modal), Design — single `@hello-pangea/dnd` list, no section headers.

---

## Q3: localStorage persistence shape

**Question:** What should be saved to localStorage and how?

**Options considered:**
- Last-used config only (single).
- Multiple named presets (admin saves named selections).
- Last-used + a built-in default (saved last-used + "Reset to default" button loading a hardcoded list).

**Decision:** Last-used + a built-in default.

**Rationale:** Covers the common case (admin uses the same columns repeatedly) without the maintenance burden of named presets. Reset button gives a clean recovery path when the saved config gets disorganized.

**Affects:** Requirement 2 (Reset button), Requirement 10 (localStorage handling), Design — single localStorage key `user_csv_export_config`, default config derived from the backend registry.

---

## Q4: Export history UI

**Question:** How should the export history page handle the two export types?

**Options considered:**
- One page, type filter — single table with Type column, dropdown filter (All/PDF/CSV).
- One page, two tabs — tabs for PDF and CSV.
- Two separate sidebar entries — keep them separate.

**Decision:** One page, type filter.

**Rationale:** Aligns with the unified-table backend choice (Q1). Tabs would imply two queries; type filter is a single query with a `WHERE type = ?` clause. Filter UX is consistent with the existing `template_id` filter on the same page.

**Affects:** Requirement 7 (unified history), Requirement 8 (sidebar move), Design — extend existing `useGetExportHistoryQuery` with `type` query param.

---

## Q5: Trigger button on user list

**Question:** Where should the "Export CSV" action be triggered from on the user list screen?

**Options considered:**
- New separate button (two buttons: "Export PDF" and "Export CSV").
- Single "Export" menu (dropdown menu showing PDF and CSV).
- Same modal, format toggle (reuse existing modal with format selector).

**Decision:** New separate button.

**Rationale:** Distinct permissions (`PDF_TEMPLATE_EXPORT` vs `USER_EXPORT_CSV`) make visibility different per role. Keeping the buttons separate avoids the awkward case of an enabled menu with all items disabled. Modal contents differ entirely (template picker vs property list), so reuse would harm both flows.

**Affects:** Requirement 1, Design — new `ExportCsvModal` component sibling to `ExportPdfModal`.

---

## Q6: Permission

**Question:** What permission(s) should govern CSV export?

**Options considered:**
- New `USER_EXPORT_CSV`.
- Reuse `PDF_TEMPLATE_EXPORT`.
- New `USER_EXPORT` covering both formats.

**Decision:** New `USER_EXPORT_CSV`.

**Rationale:** Enables fine-grained role control (e.g., HR may need CSV but not PDF, or vice versa). Migrating the existing PDF permission to a generic name would touch every role and risk regressions for an unrelated benefit.

**Affects:** Requirement 9 (permissions), Design — Laratrust permission seeder addition with backend enum `UserExportCsv = 'user.csv.export'` and frontend enum `USER_EXPORT_CSV`.

---

## Q7: Cell value format

**Question:** Should fields be exported with the displayed/translated value or raw value?

**Options considered:**
- Display value (translated) — enums translated, dates formatted, booleans "Yes"/"No".
- Raw DB value — enum codes, ISO dates, 0/1.
- Mix: dates formatted, enums raw.

**Decision:** Display value (translated).

**Rationale:** CSVs are typically opened in Excel by humans for review; raw codes are unfriendly. The trade-off is round-trip fidelity (this is export-only, so re-import isn't a concern in this release).

**Affects:** Requirement 4 (cell formatting), Design — `CsvPropertyResolver` calls `EnumHelper`-equivalent on backend.

---

## Q8: Header language

**Question:** What language should the CSV header row use?

**Options considered:**
- Admin's current UI locale.
- Always Japanese.
- User-chooses in modal.

**Decision:** Always Japanese.

**Rationale:** The company is Japanese; CSVs are consumed internally by Japanese-locale tools. Locking the header avoids producing files whose columns vary based on who clicked Export, which would break downstream pipelines that key off header strings.

**Affects:** Requirement 4, Requirement 5 (file format), Design — backend renders headers from a Japanese-translation-keyed registry, not from the request's `Accept-Language`.

---

## Q9: Default field selection

**Question:** What should the default field selection (the built-in "Reset to default") include?

**Options considered:**
- All fields, in form order.
- Common subset (basic identity fields).
- Defer — specify later.

**Decision:** All fields, in form order.

**Rationale:** "Show everything by default; let admins narrow down" matches the spirit of explorability. Avoids debating which fields are "common".

**Affects:** Requirement 3 (canonical list), Requirement 10 (default config), Design — default config selects all 31 registry keys in registry order.

---

## Q10: CSV encoding

**Question:** Should the CSV file contain Japanese-style metadata for compatibility?

**Options considered:**
- UTF-8 with BOM.
- Shift_JIS (CP932).
- UTF-8 (no BOM).

**Decision:** UTF-8 with BOM.

**Rationale:** Japanese Excel opens UTF-8-with-BOM correctly; non-BOM UTF-8 produces mojibake on double-click. Shift_JIS would mojibake non-Japanese characters (foreign names, etc.). UTF-8 + BOM is the only option that handles both.

**Affects:** Requirement 5 (file format), Design — `league/csv` writer with `setOutputBOM(BOM::UTF8)`.

---

## Q11: Per-batch-item tracking

**Question:** Is per-batch-item tracking needed for CSV like PDF, or just a single file artifact?

**Options considered:**
- Single artifact (no items).
- Per-user items + 1 final CSV.

**Decision:** Single artifact (no items).

**Rationale:** Unlike PDF where each user produces a separate file with potential individual failures, a CSV is one transactional artifact — if any row generation fails, the whole file is invalid. Per-user items would add a noisy table without operational value.

**Affects:** Requirement 6 (no `ExportBatchItem` rows for CSV), Design — `total_count` set at batch creation, `completed_count` mirrors `total_count` on success.

---

## Q12: Failure semantics

**Question:** When CSV export fails partway, what should happen?

**Options considered:**
- Mark batch failed, no partial file.
- Save partial CSV if rows succeeded.

**Decision:** Mark batch failed, no partial file.

**Rationale:** A partial CSV is more dangerous than no CSV — admins may not notice rows are missing and act on incomplete data. All-or-nothing semantics are clearer.

**Affects:** Requirement 6 (failure behavior), Design — `CsvExportJob` writes to a temp file, only uploads to S3 on full success.

---

## Q13: Modal preview

**Question:** Should the property-selection modal allow the admin to preview the first row(s) before exporting?

**Options considered:**
- No preview (just export).
- Show column headers preview.
- Preview first 3 rows.

**Decision:** No preview (just export).

**Rationale:** Headers are always Japanese and stable; preview adds endpoint complexity for marginal value. Admins can always cancel and re-open if the file looks wrong.

**Affects:** Requirement 2 (no preview pane in modal), Design — modal has no preview-related API call.

---

## Q14: User selection model

**Question:** Should CSV exports inherit the same `select_all + filters` user-list selection model as PDF, including unlimited user count?

**Options considered:**
- Same as PDF (select_all + filters).
- Same selection, but cap rows.

**Decision:** Same as PDF (select_all + filters).

**Rationale:** Reuses existing wiring on the user list page (`selectedUserIds`, `selectAll`, `totalUsers`, `filters` props). No row cap for the first release; can be added later if file sizes become a problem.

**Affects:** Requirement 1 (user selection), Requirement 6 (`select_all + filters` payload stored on batch), Design — `ExportCsvModal` accepts identical props to `ExportPdfModal`.

---

## Notes for future revisions

- If a column count or row count limit is added later, capture it as a new entry here.
- If the property list grows beyond the user_form.tsx scope (e.g., includes employment history), revisit Q9.
- If named presets become a request, revisit Q3.
