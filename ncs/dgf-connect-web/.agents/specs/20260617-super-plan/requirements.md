# Shift Planning — Requirements

> Status: **shaped** — updated after decision round 5 and the chip-level
> draft/live, minimum-window, and Start Editing lock decisions on 2026-06-18.
> This file is the current source of truth; `review-notes.md` keeps the review
> history and superseded decisions.

A spreadsheet-like **future planning** page for admins to draft workforce plans for
one workplace across an upcoming window (the day after tomorrow through the end
of next month). Rows = employees, columns = physical `Shift.date`, cells = the
shift(s) that employee is planned to work on that start date. The page is
rendered with a high-performance data grid (`@glideapps/glide-data-grid`).

Planning is intentionally silent: admins may save and revise drafts without
notifying employees. Finalizing turns the planned drafts into real `ShiftUser`
rows and notifies employees exactly as the existing shift-detail page does today —
no Shift Planner-specific notification. The natural unit of planning is a single shift,
but finalizing shift-by-shift would be tedious, so the admin selects one or more
whole date columns and finalizes every planned shift in them at once. Finalization
is atomic per date column (a single failing shift rolls back only that date, never
the others; see §3.3). Draft/live is an assignment-chip state, not a date/column
state: draft chips and live chips may coexist in the same cell/date, and the grid
never switches a whole date into a separate live-edit mode.

Naming convention: use **Shift Planner** for the user-facing page/feature label and
**Shift Planning** for technical/domain names (for example
`/manage/shift-planning`, `shift_plan_drafts`, and
`shift_planning_lock:{workplace_id}`).

---

## 1. Background — current business model (as-is)

Two-layer shift model:

- **`WorkplaceShift`** = a shift *template/type* for a workplace (e.g. "Morning
  08:00-17:00"). Holds start/end/break times, check-in rounding, `is_active`,
  `date_adjustment` (night shifts counting toward another payroll/business day),
  optional leaders, and salary override. A workplace can have several.
- **`Shift`** = a concrete instance of one `WorkplaceShift` on one physical
  `date`. Carries `fixed_total`, `temp_total`, `max_registered_temp`,
  `break_minutes`, `adjusted_date`. This row must exist before anyone can be
  assigned.
- **`ShiftUser`** = a live assignment (`shift_id` x `user_id`):
  `workplace_position_id`, `is_fixed`, `status`, `is_overtime_allowed`,
  `is_absent`, `approver_id`/`confirmed_at`, plus check-in / report / absence
  relations. Uniquely constrained on `(shift_id, user_id)`.

Existing write path: `AssignShiftUsersAction` operates on one concrete `Shift` and
replaces that shift's full roster. It validates permissions, capacity, workplace
membership, user validity, work-hour rules across a +/-6-day window, then writes
live `ShiftUser` rows and dispatches approval/rejection side effects.

### Why Shift Planner needs new plumbing

- The grid edits many users, dates, and shift types at once; the existing endpoint
  is shaped around one full shift roster.
- Work-hour validation is cross-cell: one assignment can invalidate another
  assignment within the same batch.
- Draft planning must not create employee-visible assignments or notifications.
- Finalization needs a deliberate notification boundary and a set-aware validator.
- `@glideapps/glide-data-grid` is not yet installed.

---

## 2. Shaped decisions

| # | Decision | Choice |
|---|----------|--------|
| 1 | Cell model | **Multiple shifts per physical-date cell** (usually 0-2 chips) |
| 2 | Page scope | **One workplace at a time**, future planning only |
| 3 | Date model | Columns use physical **`Shift.date`**; `adjusted_date` remains backend/payroll metadata |
| 4 | Planning model | **Draft first, finalize later**; drafts are silent and not employee-visible |
| 5 | Finalize scope | Admin selects one or more physical-date columns and clicks **Finalize** |
| 6 | Assign status on finalize | Finalized draft rows become live **Approved** `ShiftUser` rows |
| 7 | Batch validation UX | **Per-date atomic.** Each selected date commits or fails on its own; valid dates commit, failing dates report exact per-cell errors so the admin can fix and resubmit |
| 8 | Capacity | **Block with error** when approved count exceeds `fixed_total` / `temp_total`, using existing count logic over the projected final state. The error must split the planner's own contribution from pre-existing live rows (e.g. self-registrations, other admins) so the admin can act on it |
| 9 | Position | **Pick in cell editor** per assignment; defaults to the employee's **most-recently-used position on this workplace** |
| 10 | Shift creation | **Explicit manual create** when a concrete `Shift` does not exist |
| 11 | Copy/paste | **Dropped from v1**; revisit after core planning/finalize is stable |
| 12 | Notifications | **No notification on draft save.** Finalize dispatches the existing `ShiftUserApproved` event per committed draft addition; the existing `CreateNotificationForUser` listener fires the existing `SHIFT_APPROVED` notification + `ShiftUserScheduledEmail` + document-requirement checks unchanged. **No new notification type, no new email, no Shift Planning-specific gating flag.** Removals dispatch `ShiftUserRejected` and stay silent (existing listener no-ops on manager-created rows) |
| 13 | Window | **Day after tomorrow → end of next month** (variable length, month-stepped paging); never starts before `today + 2 days` in JST. Horizontal scroll with frozen rows/columns handles width; performance re-evaluated after implementation |
| 14 | Draft sharing | **Whole-workplace planning lock.** The page opens read-only. An admin presses **Start editing** to acquire the workplace lock and enable writes. Other admins stay read-only with a "currently editing by {name}" banner. The lock does **not** block the normal shift-detail page or employee self-registration — those remain concurrent live writes handled optimistically (re-read + unique index + finalize re-validation) |
| 15 | Draft storage | **Relational table** (`shift_plan_drafts`), shaped like `ShiftUser` plus an `action` flag; separate from `ShiftUser`; cleaned up after finalize |
| 16 | Draft/live status | **Chip-level only.** A draft chip is backed by `shift_plan_drafts`; a live chip is backed by `ShiftUser`. There is no draft-mode/live-mode date, no finalized-date marker, and no derived date mode |
| 17 | Live-row coexistence | **Live rows coexist with draft rows.** Shift Planner always loads live `ShiftUser` rows plus draft rows. New planned additions are saved as draft chips until finalized. Live chips display confirmation status, but confirmation is visual only and does not block save/finalize |

Not prioritized in this pass: copy/paste, drag-fill, one-click "Apply fixed
schedules".

---

## 3. Functional requirements

### 3.1 Layout & scope

- Top bar: workplace selector + physical-date window covering **the day after
  tomorrow through the end of next month**, with month-stepped paging. The window
  is variable length (~30-60 columns) and never starts before `today + 2 days`
  in JST; today and tomorrow are not plannable here.
- Rows = members of the selected workplace.
- Columns = physical `Shift.date` values, not `adjusted_date`.
- Cell holds 0..n shift chips. Each chip is either a **draft chip** (planned,
  silent — a `shift_plan_drafts` row) or a **live chip** (committed — a
  `ShiftUser` row). Draft vs live status is rendered on the chip, not on the
  column/date. A cell/date may contain both draft and live chips.
- Live chips show confirmation status (`confirmed_at` present vs not) as visual
  metadata. Confirmation is not an edit/finalize blocker in Shift Planner.
- Weekend and server-locked shift slots get visual treatment. Because the window
  starts at `today + 2 days`, checked-in rows should not exist in normal use; the
  server still enforces the minimum planning date.
- `adjusted_date` remains backend/payroll metadata only; it does not drive grid
  column position, editing, or notification grouping in v1.
- **Planning lock:** the page opens read-only. Pressing **Start editing** acquires
  the workplace lock; only then are Save Draft, live-chip writes, and Finalize
  enabled. If held by another admin, the page stays read-only with a banner
  identifying the current editor. The lock is heartbeat-renewed while edit mode is
  active and auto-releases after inactivity (TTL expiry) or on explicit Leave.
  Finalize requires the lock. Mechanism is a Redis cache entry with TTL, not a DB
  table — see §4.1.

### 3.2 Draft editing (chip-level drafts)

- Cell editor: `[Shift-type ▼] [Position ▼] [Fixed/Temp] [Add]`, plus existing chips
  each with remove action where editable. Position defaults to the employee's
  most-recently-used position on this workplace.
- If a selected `WorkplaceShift` has no concrete `Shift` for the physical column
  date, the editor opens the existing create-shift modal. Admin enters
  `fixed_total`, `temp_total`, `max_registered_temp`, and `break_minutes`; the
  created `Shift` persists even if draft changes are later discarded.
- New planned additions save as Shift Planning draft data, not live `ShiftUser` rows.
  Existing live chips remain live until an explicit live write or finalized draft
  removal changes them; a live chip's confirmation status does not block saving.
- Draft saves do not dispatch shift approval/rejection notifications, do not send
  email, and do not affect employee schedule APIs.
- `max_registered_temp` is **not displayed or validated** in the grid. (Once
  finalized, planner-approved temps are real `ShiftUser` rows and therefore count
  against `max_registered_temp` for employee self-registration exactly as any other
  approved temp does today — inherited, unchanged.)

### 3.3 Finalize (draft → live)

- Admin selects one or more physical-date columns and clicks **Finalize**.
- Finalize is **per-date atomic**: each selected date is validated and committed
  independently. Valid dates commit; failing dates commit nothing and report their
  exact per-cell errors so the admin can fix and resubmit. A stale cell on one
  date does not automatically roll back other dates.
- Failing cells light up red with the reason on hover. Capacity errors must split
  the planner's own contribution from pre-existing live rows.
- If the workplace planning lock is held by another admin, save/finalize is not
  allowed. User confirmation (`confirmed_at`) is display-only and does not block
  finalize. Check-in conflicts are outside normal v1 because the window starts at
  `today + 2 days` in JST; the server rejects any request outside that minimum
  planning window.
- On successful finalize, per committing date:
  - Draft `add` rows are committed as live **Approved** `ShiftUser` rows, each
    dispatching `ShiftUserApproved` → existing `SHIFT_APPROVED` notification +
    `ShiftUserScheduledEmail` + document-requirement checks (one notification/email
    per added assignment, same as the shift-detail page today).
  - Draft `remove` rows set the matching live `ShiftUser` to Rejected using existing
    cleanup semantics (null `confirmed_at`, delete that user's check-ins, dispatch
    `ShiftUserRejected`). These stay silent because planner-created rows carry
    `created_by`.
  - Committed draft rows for that date are deleted.
  - Chat participants are synced once per touched shift; both events fire.
  - Existing scheduled reminder jobs remain unchanged.

### 3.4 Chip-level draft/live behavior

- There is **no draft-mode/live-mode date**. The grid always renders the projection
  of live `ShiftUser` rows plus `shift_plan_drafts` rows for the selected workplace
  and window. Draft/live status belongs to each chip.
- Draft chips are silent and employee-invisible until finalized. Live chips are
  already employee-visible and are included in validation and capacity counts.
- Draft changes may coexist with live chips in the same date column, shift slot, and
  employee cell. A self-registration or shift-detail write does not flip the whole
  date into another mode; it simply appears as a live chip next to any draft chips.
- Finalize applies only draft rows in the selected physical-date columns. Already
  live chips are validation inputs and are not re-notified.
- Live chips must show whether the user has confirmed (`confirmed_at` set) or not
  confirmed yet. This is visual metadata only and does not make the chip read-only
  or block Save Draft/Finalize.
- There is **no finalized-date marker, no derived date mode, and no re-finalize
  concept**. Subsequent planning changes are represented by draft chips/removal
  overlays and are finalized with the same date-column Finalize action.

---

## 4. Technical design (proposed)

### 4.1 Backend endpoints

- **`GET /manage/shift-planning?workplace_id&from`** returns the window from `from`
  (defaults to the day after tomorrow) through the end of the following month:
  - `workplace`
  - all active `workplace_shifts` for the workplace, plus inactive referenced shift
    types if needed for old data
  - active `positions`, plus inactive referenced positions
  - `members` with fixed-schedule details and date-relative validity data
  - concrete `shifts` for the physical date window
  - live assignments for those shifts
  - draft assignments for those physical dates
  - current lock holder (user + acquired-at), if any
- **`PUT /manage/shift-planning/draft`** saves a draft diff for one workplace into the
  shared `shift_plan_drafts` table. It never writes live `ShiftUser` rows and never
  sends notifications. Requires the workplace lock.
- **`POST /manage/shift-planning/finalize`** accepts
  `{workplace_id, dates: ["YYYY-MM-DD", ...]}` where `dates` are physical
  `Shift.date` columns. Requires the workplace lock. Flow:
  1. Permission: the page gate requires `ShiftList` + `ShiftCreate` + `ShiftEdit`
     scoped to the workplace (see §5.4).
  2. Reject any requested physical date before `today + 2 days` in JST.
  3. Re-read current live state (another admin, a self-registration, or an absent
     notice may have mutated it since GET). Build the validation state from live
     assignments plus saved draft changes.
  4. **Validate per date immediately before that date commits**, per the set-aware
     contract (§4.3): for each candidate, the work-hour map includes live
     `ShiftUser` rows and relevant draft rows, while excluding the candidate itself.
     Check workplace membership, user validity, capacity, and work-hour rules.
  5. **Per-date atomic commit:** each selected date is its own transaction. Within a
     committing date, write `ShiftUser`s (Approved for `add`, Rejected for `remove`
     with existing cleanup), fire `ShiftUserApproved`/`ShiftUserRejected` for
     side effects, sync shift chat participants once per touched shift, and delete
     that date's committed draft rows. Failing dates commit nothing and return
     their per-cell errors; valid dates still commit.
  6. Notifications are emitted by the existing listeners on the events dispatched in
     step 5 — no Shift Planning-specific notification code.
- **`POST /manage/shift-planning/lock`** / **`DELETE .../lock`**: called by
  **Start editing** to acquire (with heartbeat renew) / release the workplace
  planning lock. Acquire is first-writer-wins while unexpired; supports a
  force-takeover after expiry with confirmation. **Mechanism: a Redis-backed cache entry** — *not* the `Cache::lock`
  atomic-lock API (no TTL-extend or holder metadata) and *not* a DB table (the lock
  is ephemeral + advisory; TTL auto-cleans). Key `shift_planning_lock:{workplace_id}`
  holds a JSON payload `{user_id, name, acquired_at}` with a TTL:
  - **Acquire** = `Cache::add($key, $payload, $ttl)` — atomic first-writer-wins,
    fails if held.
  - **Heartbeat** = `Cache::put($key, $payload, $ttl)` by the owner; the active tab
    renews on an interval shorter than the TTL.
  - **Banner** = `Cache::get($key)` returns `{name, acquired_at}` directly.
  - **Leave / takeover** = `Cache::forget($key)` by the owner; expiry is automatic,
    so a fresh `add` succeeds once the lock goes stale.
  - The heartbeat/takeover race (two admins grabbing a just-expired lock) is benign
    and **accepted**: the lock is advisory — finalize re-reads and re-validates live
    state (step 3 above) and the lock never blocks the shift-detail page or
    self-registration (Decision 14). Optional hardening: wrap acquire/takeover in a
    short `Cache::lock` mutex; not built in v1.

### 4.2 Draft storage

Drafts live in a dedicated **relational table** shaped like `ShiftUser`, not a JSON
blob. The deciding factors: a single editor per workplace (lock) keeps it simple,
and finalize is a near-straight `INSERT ... on conflict update` when the row mirrors
`ShiftUser`.

```
shift_plan_drafts
  id
  workplace_id
  date                  -- physical Shift.date
  workplace_shift_id
  user_id
  workplace_position_id
  is_fixed
  action enum('add','remove')   -- "keep" is implicit: no row => fall through to the live ShiftUser
  created_by, updated_by, timestamps
  unique (workplace_id, date, workplace_shift_id, user_id)
```

- **Single editor (lock).** Because the workplace lock guarantees one Shift Planner
  editor at a time, draft rows do **not** need `updated_at` cell-conflict tokens.
- **Projection contract.** The grid and the finalize validator both render/compute
  the plan by combining live `ShiftUser` rows with draft rows. `action='add'`
  creates a draft chip; `action='remove'` represents a planned removal overlay for
  an existing live row. This projection is chip-level only; it never derives a
  date/column mode.
- **Cleanup on finalize.** Committed draft rows for a committing date are deleted.
- **Cleanup on live changes.** Whenever a live `ShiftUser` is created/updated
  (existing shift-detail write path, and employee self-registration), delete any
  matching stale `add` draft for
  `(workplace_id, date, workplace_shift_id, user_id)` so it cannot fight a real live
  row. When a matching live row is rejected/removed by another path, delete any
  matching stale `remove` draft.
- **Cleanup of stale minimum-window drafts.** A scheduled job deletes
  `shift_plan_drafts where date < today + 2 days (JST)` daily.

Draft data must remain separate from live `ShiftUser` rows so employee schedule
queries and reminder jobs see only finalized assignments.

### 4.3 Set-aware validation

`validateShiftRegister` stays **unchanged and reused as-is**: it already consumes a
plain in-memory per-user work-data map and adds the candidate's own contribution
itself (overlap merge + `+ workingHours` for the weekly cap), so it never reads the
DB. Shift Planning wraps it with a set-aware map builder. For each candidate the map
must include the user's other relevant live assignments and draft assignments, but
exclude the candidate currently being tested, so the final schedule is validated
coherently without double-counting.

Cross-date effects to account for:

- weekly/month-window work-hour limits over the existing +/-6-day validation window;
- same-day shift-count limits (e.g. AM + PM on the same physical date);
- overlapping/adjacent ranges, especially night shifts crossing midnight;
- date-relative user validity/membership checks.

Capacity remains per concrete shift slot (`fixed_total` / `temp_total`), but it must
count the projected approved rows for that slot: existing live rows plus draft adds
for that shift, minus draft removes being committed for that same date.

**Chosen approach: compute the live DB base for the relevant validation window,
then layer draft deltas in memory.** The rejected alternative — extending
`getWorkHours` to exclude multiple
`ignoreShiftId`s — cannot work for Shift Planner: `getWorkHours` only reads live
`ShiftUser` rows already in the DB, so it can *subtract* existing shifts but has no
way to *inject* a planned addition that is still a draft. Exclusion solves only the
removal half and leaves additions — the core of finalize — uncounted.

Plan:

1. **Extract** the per-shift work-data math currently inlined in `getWorkHours`
   (`getShiftTimes` → `getShiftWorkDuration` → `{total, hours, ranges, shift_count}`)
   into a reusable helper, e.g. `WorkHelper::computeShiftWorkData($date,
   $workplaceShift)`, and have `getWorkHours` call it. This keeps the addition math
   identical to the live path.
2. For each date being finalized, call `getWorkHours()` (no ignore) for all affected
   users over the required validation window to get the current live DB base.
3. Build each candidate's per-user map in memory: live DB base **plus** the user's
   other relevant draft `add` rows (via `computeShiftWorkData`), **minus** relevant
   draft `remove` rows that are part of the date currently being committed, and
   **minus** the candidate itself. Do not let a date commit by relying on a draft
   removal from a different date that has not committed.
4. Pass that synthetic map to `validateShiftRegister` unchanged; check workplace
   membership, date-relative user validity, and capacity alongside.

This keeps validation set-aware while preserving per-date atomic commits.

Test matrix:

- AM + PM same physical date
- multi-date finalize across a 7-day selected window
- remove then add the same user/shift in one finalize
- night shift adjacent to a day shift
- selected date with pending employee self-registrations and draft chips on the
  same date

### 4.4 Notifications

Finalize reuses the existing event stack verbatim — `ShiftUserApproved` per
committed draft addition → `CreateNotificationForUser` (notification + email) and
`CheckWorkplaceDocumentRequirementsListener`. Removals dispatch `ShiftUserRejected`
and stay silent via the existing `created_by` guard. **No new `NotificationType`, no
new email, no Shift Planning-specific source/gating flag** (the flag was only ever needed for
notification control, which is no longer required). One notification/email per added
assignment, identical to the current shift-detail page.

### 4.5 Frontend

- Add `@glideapps/glide-data-grid`.
- Sticky employee column; physical-date columns with weekend/min-date/locked-state
  shading and horizontal scroll (frozen rows/columns).
- Custom chip renderer for draft vs live (finalized) assignments.
- **Planning-lock banner**: page opens read-only; **Start editing** acquires the
  workplace lock, enables edit controls, and starts heartbeat. If the lock is held
  elsewhere, keep read-only mode with "currently editing by {name}".
- Staged local diff + Save Draft action for draft chips/removal overlays.
- Column selection + Finalize action for selected physical-date columns containing
  draft rows.
- Finalize result modal showing committed dates, notifications sent, validation
  errors (with planner-vs-pre-existing split for capacity), and conflicts.
- Position dropdown defaults to the employee's most-recently-used position on the
  workplace.
- No copy/paste controls in v1.
- Performance: load the whole window upfront in v1; add viewport-based lazy cell
  hydration (grid supports it natively via `getCellContent`; needs a backend
  range endpoint) only if a real workplace measures poorly.

---

## 5. Assumptions

1. **`is_fixed` default:** the Fixed/Temp toggle defaults to Fixed when the employee
   has a matching `FixedSchedule` for the physical weekday + shift-type, else Temp.
   Admin can override. Approving fixed directly is consistent with the existing
   shift-detail page, where admins already assign fixed-approved freely (the
   fixed-schedule membership check only runs on pending-fixed users).
2. **Capacity:** `fixed_total` and `temp_total` are demand targets set on concrete
   `Shift` creation/config. Shift Planner does not auto-grow or shrink them.
   `max_registered_temp` is ignored in the grid (see §3.2).
3. **Rows:** all workplace members; sortable by name/staff_no; Fixed-vs-Temp /
   position filter; regulars with matching fixed schedules sort to top.
4. **Permission:** the **page gate requires `ShiftList` + `ShiftCreate` +
   `ShiftEdit`** scoped to the workplace. Anyone who can open Shift Planner can list,
   create concrete shifts, and edit/finalize plan data.
5. **Future-only:** Shift Planner does not replace near-term operational shift detail
   management; its minimum planning date is `today + 2 days` in JST. Today and
   tomorrow are not editable in Shift Planner.
6. **Timezone:** all `today + 2 days` boundaries are evaluated in JST (the app
   timezone). No UTC/JST conversion logic is required in v1.

---

## 6. Deferred follow-ups (not blockers)

- **Apply fixed schedules** bulk action is out of v1. Keep the grid manual plus
  finalization for this implementation pass; revisit after core planning/finalize
  is stable.

### Resolved since round 3

- **Finalize notification channel** — reuse the existing `ShiftUserApproved` event
  and listener stack. No new notification type or email. See Decision 12 / §4.4.
- **Planning lock vs optimistic cell conflict** — whole-workplace planning lock
  replaces per-cell `updated_at` conflict detection. See Decision 14.
- **Finalized-date marker table / date mode** — dropped. Draft/live status is
  chip-level only; no date/column mode is stored or derived. See Decision 16.
- **Re-finalize / delta notify** — no separate concept. Draft rows are finalized
  with the same date-column Finalize action; already-live chips are not re-notified.
  See Decision 17 / §3.4.
- **Draft cleanup** — committed on finalize, cleared on matching live `ShiftUser`
  changes, and purged daily for dates before the minimum planning window. See §4.2.
- **Position default** — most-recently-used position on the workplace. See Decision 9.
- **Self-registration conflict** — no special handling. `temp_total` counts approved;
  self-registration and planner assignment contend for the same pool as today. If a
  self-registration fills the last slot before finalize, finalize reports the
  over-capacity error (with planner-vs-pre-existing split) and the admin resubmits.
- **Set-aware validation approach** — compute the live DB base for the relevant
  validation window and layer draft deltas in memory; `validateShiftRegister` reused
  unchanged. Multi-shift exclusion rejected (`getWorkHours` can't inject
  not-yet-live additions). See §4.3.
- **Planning-lock mechanism** — Redis cache entry (`Cache::add`/`put` with TTL + JSON
  holder payload), not the `Cache::lock` atomic API and not a DB table. See §4.1.
