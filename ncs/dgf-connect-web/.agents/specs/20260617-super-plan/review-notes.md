# Shift Planning — Review Notes (combined)

> Status: **review round 5** — 2026-06-18. Consolidates the initial Claude/Codex
> reviews plus author decisions through the chip-level draft/live, minimum-window,
> and Start Editing lock decisions.
>
> Current source of truth: [`requirements.md`](./requirements.md). This file keeps
> decision history, superseded choices, and implementation risks.
>
> Attribution tags: **[C]** Claude · **[X]** Codex · **[A]** author.

Naming convention: **Shift Planner** is the user-facing page/feature label;
**Shift Planning** is used for technical/domain names such as
`/manage/shift-planning`, `shift_plan_drafts`, and
`shift_planning_lock:{workplace_id}`.

---

## 0. Resolved decisions — current

| # | Topic | Decision | Resulting design notes |
|---|-------|----------|------------------------|
| D1 | Shift creation | **Explicit, not auto.** Cell editor lists all `WorkplaceShift`; a master shift with no concrete `Shift` opens the create modal. | Create = `ShiftCreate`; needs unique `(date, workplace_shift_id)` (see §2.2). |
| D2 | Capacity on over-assign | **Block with error.** Totals stay the demand target set at creation. | Drop auto-grow/shrink. Validate approved count <= `fixed_total` / `temp_total` during finalize. |
| D3 | Self-registration | **Coexist.** Employees still self-register as temp. | Planner must not collapse `temp_total`/`max_registered_temp` to assigned count. Concurrent self-registration is handled by re-reading live rows and validating live + draft state before finalize. |
| D4 | Remove semantics | **Set Rejected** (match existing): null `confirmed_at`, delete that user's check-ins, fire `ShiftUserRejected`. | Keep `SyncShiftChatParticipantsAction::batch` wrapper + both events. |
| D5 | Planning notifications | **Drafts are silent; finalize sends notification.** Admin can revise draft plans without notifying employees. | Do not write employee-visible `ShiftUser` rows on draft save. Finalize dispatches the existing approval event per committed draft addition. |
| D5a | Employee confirmation | **Still required.** Finalized planner assignments keep the `confirmed_at` flow. | Do not auto-set `confirmed_at`. Scheduled reminders remain unchanged. |
| D6 | Fixed/Temp toggle | **Default from FixedSchedule, overridable.** Pre-select Fixed when a matching schedule exists for physical weekday + shift-type, else Temp. | No hard enforcement on approved for v1; keep UI copy honest. |
| D7 | Empty created shift on Discard | **Keep it.** Discard reverts draft assignments; the concrete `Shift` persists. | Grid distinguishes "no `Shift` yet (create)" vs "`Shift` exists, 0 assigned". |
| D8 | Copy/paste | **Dropped from v1.** | Removes paste replace/auto-create complexity; revisit later. |
| D9 | Column keying | **Physical `Shift.date`.** | `adjusted_date` remains backend/payroll metadata only. No client/backend date inversion contract needed. |
| D10 | Future-only window | **Shift Planner starts at `today + 2 days` (JST).** | Today and tomorrow are not editable in Shift Planner; this avoids check-in timing conflicts in normal v1 usage. |
| D11 | Confirmed/check-in handling | **Confirmation is visual only; check-in is out of window.** | Live chips show `confirmed_at` status, but confirmation does not block save/finalize. Server rejects requests before the minimum planning date. |
| D12 | Holiday shading | **Drop for v1** (no calendar source exists). | Keep weekend/min-date/locked-state shading only. |
| D13 | Finalize model | **Select physical-date column(s), then Finalize.** | Finalize validates, commits live Approved `ShiftUser` rows, and notifies. |
| D14 | Draft storage | **Separate from `ShiftUser`.** | Drafts must not affect employee schedule APIs, reminders, or notifications. |
| D15 | Draft sharing & concurrency | **Whole-workplace planning lock with Start Editing.** | Page opens read-only. Press **Start editing** to acquire the workplace lock and enable writes; others stay read-only with "editing by {name}" banner. Lock does not block the shift-detail page or self-registration; those stay concurrent live writes handled optimistically. |
| D16 | Draft storage shape | **Relational `shift_plan_drafts` table, shaped like `ShiftUser` + `action` flag.** | `action` is `add`/`remove`; cleaned up per committing date after finalize. Single editor (lock) means no per-cell conflict tokens are needed. |
| D17 | Draft/live status | **Chip-level only.** | No draft-mode/live-mode date, no marker table, and no derived date mode. Draft chips come from `shift_plan_drafts`; live chips come from `ShiftUser`; they may coexist in the same cell/date. |
| D18 | Finalize atomicity | **Per-date atomic, not whole-selection.** | Each selected date validates immediately before its own transaction and commits/fails independently. Work-hour validation counts live rows plus relevant draft rows and must not let a date rely on an uncommitted draft removal from another date. |
| D19 | Re-finalize/date live-mode | **No date-level re-finalize/live-edit mode.** | Finalize applies draft rows in selected date columns; already-live chips are validation inputs and are not re-notified. Subsequent planning changes remain chip-level drafts/removal overlays. |
| D20 | Window | **Day after tomorrow → end of next month, month-stepped paging.** | Variable-length window (~30-60 columns); today and tomorrow are not plannable in Shift Planner. Horizontal scroll with frozen rows/columns; perf re-evaluated post-implementation. |
| D21 | Page permission gate | **`ShiftList` + `ShiftCreate` + `ShiftEdit`.** | Anyone who can open Shift Planner can list, create concrete shifts, and edit/finalize plan data. |
| D22 | Position default | **Most-recently-used position on the workplace.** | Cell editor pre-selects the employee's latest position on this workplace. |
| D23 | `max_registered_temp` in grid | **Ignored.** | Not displayed or validated in the grid. Finalized temps still count against it for self-registration (inherited, unchanged). |
| D24 | Capacity error detail | **Split planner-vs-pre-existing.** | Over-capacity errors must distinguish the planner's own contribution from pre-existing live rows (self-registration, other admins). |
| D25 | Draft lifecycle cleanup | **Three paths.** | Draft rows are deleted: on finalize (committed), on matching live `ShiftUser` changes, and daily for dates before the minimum planning window (`today + 2 days`, JST). |
| D26 | Timezone | **JST.** | All `today + 2 days` boundaries evaluated in JST; no UTC conversion in v1. |

### Deferred follow-ups created by current decisions

- **Apply fixed schedules:** out of v1. Keep the grid manual plus finalization for
  this implementation pass; revisit after core planning/finalize is stable.

### Closed since round 3

- **Finalize notification channel** — reuse the existing `ShiftUserApproved` event
  and listener stack. No new notification type or email. (D12 / §4.4)
- **Planning lock vs optimistic cell conflict** — whole-workplace lock replaces
  per-cell `updated_at` conflict detection. (D15)
- **Finalized-date marker table / date mode** — dropped. Draft/live is chip-level
  only and is not derived from live-row presence. (D17)
- **Re-finalize / delta notify** — no separate date-level concept; selected draft
  rows finalize, already-live chips are not re-notified. (D19)
- **Multi-admin draft ownership** — resolved by the planning lock (D15).
- **Self-registration conflict** — no special handling. temp_total counts approved;
  planner and self-registration contend for the same pool as today. Over-capacity at
  finalize surfaces as a per-cell error with planner-vs-pre-existing split (D18/D24)
  and the admin resubmits.

### Closed since round 2

- **Re-finalize policy** — superseded by D19 (chip-level drafts; no date-level re-finalize/live mode).

---

## 1. Superseded decisions

These were valid review-round-1 ideas but are now withdrawn.

| Superseded choice | Replacement |
|-------------------|-------------|
| Lazy-create missing `Shift` rows during batch save | Explicit create modal before drafting an assignment |
| Auto-grow/shrink capacity to assigned count | Block over-capacity; totals remain demand targets |
| Immediate planner approval with suppressed notification/reminders as the only heads-up | Silent drafts + explicit Finalize notification |
| Columns keyed by `adjusted_date` | Columns keyed by physical `Shift.date` |
| Page can browse into past read-only | Shift Planner is future planning only |
| Copy row / copy day / paste auto-create | Dropped from v1 |
| Rolling 30-day window (`from` -> `from+29`) | Day after tomorrow -> end of next month, month-stepped (D20) |
| Whole-selection all-or-nothing finalize | Per-date atomic finalize (D18) |
| JSON / undecided draft document | Relational `shift_plan_drafts` table (D16) |
| Round-3 cell-level `updated_at` conflict detection on shared drafts | Whole-workplace planning lock; single editor (D15) |
| `shift_planning_finalized_dates` marker table | No date mode; draft/live is represented per chip (D17) |
| Date-level live-mode / re-finalize with delta-only notification | Chip-level drafts/removal overlays finalized by selected date columns (D19) |
| Shift Planning-specific source/gating flag for notification control | Not needed; reuse existing events unchanged (§4.4) |

---

## 2. Critical technical risks

### 2.1 The `WorkHelper` set-aware refactor is bigger than "reuse as-is" — **[C][X]**

`validateShiftRegister` assumes the candidate shift is not in the work-hour map and
adds the candidate's own contribution itself:

- week hours via `$shift->getDuration()` (`WorkHelper.php:383-393`)
- `shift_count >=` max for the day (`WorkHelper.php:375-376`)
- overlap against neighbor ranges that exclude the candidate (`WorkHelper.php:346`)

For finalize, per candidate under validation, the map must contain every other batch
addition/removal for that user but exclude the candidate currently being tested.
Otherwise hours/shift-counts double-count and produce false passes/blocks.

`getWorkHours` only supports excluding one `ignoreShiftId` (`WorkHelper.php:242,
260`); finalize can touch many shifts and drafts that are not in the DB yet. The
chosen approach is to compute the live DB base for the relevant validation window
and layer draft deltas in memory.

**Action:** specify a final-state validator with an explicit contract ("map excludes
the current candidate") plus tests for AM+PM same day, 7-day window bulk add,
remove-then-add same cell, night shift adjacent to day shift, and pending temp
self-registrations.

### 2.2 Missing unique constraint -> duplicate-shift race — **[X]**

Shifts have only non-unique date/workplace indexes; there is no unique index on
`(date, workplace_shift_id)`. Two admins creating the same shift concurrently can
produce duplicates. This remains relevant under explicit create.

**Action:** add a unique index on `shifts(date, workplace_shift_id)` and make create
transaction-safe (`firstOrCreate`/upsert or equivalent).

### 2.3 Approval side effects fan out — **resolved (D12)**

`ShiftUserApproved` currently triggers `CreateNotificationForUser`, which creates a
notification and sends `ShiftUserScheduledEmail` (`CreateNotificationForUser.php:54`).
The same event also triggers workplace document requirement checks through
`CheckWorkplaceDocumentRequirementsListener`.

**Decision:** finalize reuses this event stack unchanged. Do not skip the event and
do not add a Shift Planning-specific source/gating flag; notification/email/document side effects
match the existing shift-detail approval behavior.

### 2.4 Draft storage is a real data model, not just frontend state — **resolved (D15/D16)**

Finalize Plan requires drafts to survive navigation and repeated admin edits without
becoming employee-visible. Resolved as a shared, persistent relational table
`shift_plan_drafts` keyed by `(workplace_id, date, workplace_shift_id, user_id)` with
an `add`/`remove` action (`keep` implicit) and audit fields, shaped like `ShiftUser`.
Because the workplace planning lock guarantees a single editor (D15), per-row
`updated_at` cell-conflict tokens are **no longer required**. The grid and finalize
validator share one chip-level projection contract: combine live `ShiftUser` rows
with draft rows (`add` => draft chip; `remove` => planned removal overlay).

---

## 3. Backend behavior to define

### 3.1 Remove semantics — **[C][X]**

Existing de-roster behavior sets status to Rejected, nulls `confirmed_at`, deletes
the user's check-ins for that shift (`AssignShiftUsersAction.php:218-219, 254`), and
dispatches `ShiftUserRejected`. Finalize should preserve this behavior for live rows
removed by a draft.

### 3.2 Keep the chat sync wrapper — **[C]**

The current write wraps the transaction in `SyncShiftChatParticipantsAction::batch`
(`AssignShiftUsersAction.php:187`) and fires both `ShiftUserApproved` and
`ShiftUserRejected` (`AssignShiftUsersAction.php:242-247`). Finalize must preserve
chat sync and both events.

### 3.3 Server-side lock/window enforcement — **[C][X]**

Shift Planner is future-only, but the server must still enforce the current decisions:

- require the workplace planning lock for writes/finalize
- reject requested physical dates before `today + 2 days` in JST
- treat `confirmed_at` as visual metadata only, not a blocker
- check-in conflicts should be impossible in normal v1 because today/tomorrow are
  outside the grid window
- re-check `is_absent` / absent notice behavior before overwrite

### 3.4 Concurrency / staleness between GET, draft save, and finalize — **[C]**

Another admin, an employee self-registration, confirmation status, or absence state
can mutate after the grid was loaded. Finalize must re-read live state, validate
against current rows plus relevant draft rows, and surface per-cell errors.

### 3.5 `is_fixed` for directly-approved users — **[C][X]**

The fixed-schedule rule is enforced only on pending fixed users
(`AssignShiftUsersAction.php:103-126`). Because finalization approves directly, an
admin can mark anyone `is_fixed`. Current decision accepts that looseness for v1,
with defaulting from physical weekday fixed schedules and explicit admin override.

---

## 4. GET / data-shape clarifications

### 4.1 Column keying = physical `Shift.date` — confirmed

Columns represent the physical date the shift starts. This matches `getWorkHours`,
`max_shift_per_day`, check-in timing, and the user's latest direction. Keep
`adjusted_date` internal for payroll/business-date calculations and same-adjusted-date
auto-reject side effects.

### 4.2 Include inactive-but-referenced positions — **[X]**

GET must return inactive positions still referenced by existing live or draft
assignments, or old chips render with no label. Precedent exists:
`getShiftDetail` already does `whereIn(ids) OR is_active` (`ShiftService.php:184-193`).

### 4.3 Member validity is date-relative, not a boolean — **[C][X]**

`checkUserExpiration` compares card expiry against `shiftDate + threshold`
(`ValidatesShiftUser.php:31-34`); a user can be valid on day 3 and invalid on day
25. GET should return raw expiry dates or per-date flags. Final authority remains
the finalize validator.

### 4.4 Draft/live display is per chip, not per date — **[A]**

A cell can hold multiple shift chips. Draft/live status is displayed on each chip; a
cell/date may contain both draft chips and live chips. Confirmation status is also
chip metadata on live chips.

---

## 5. Deferred follow-ups

- "Apply fixed schedules" is out of v1; revisit after the core planning/finalize
  flow is stable.

## 5.1 Round-5 additions

- **Planning lock (D15).** The page opens read-only. Press **Start editing** to
  acquire a Redis-cache workplace lock (`Cache::add`/heartbeat `put` with holder
  payload + TTL). No DB lock table in v1. Finalize requires the lock.
- **Draft lifecycle cleanup (D25).** Three deletion paths: committed on finalize,
  matching stale drafts cleared on live `ShiftUser` changes (covers shift-detail
  writes and self-registration), and daily purge of `date < today + 2 days (JST)`.
- **Chip-level draft/live (D17).** There is no draft-mode/live-mode date. Draft
  chips (`shift_plan_drafts`) and live chips (`ShiftUser`) may coexist in the same
  cell/date; confirmation status is displayed on live chips and does not block save.
- **Minimum planning window (D20/D26).** Shift Planner starts at `today + 2 days` in
  JST. Today and tomorrow are out of scope, so check-in conflicts are not expected
  in normal v1 usage.
- **Capacity error detail (D24).** Finalize over-capacity errors must split the
  planner's own contribution from pre-existing live rows (self-registrations, other
  admins) so the admin can act.
- **Page permission gate (D21).** Open Shift Planner requires `ShiftList` +
  `ShiftCreate` + `ShiftEdit`.

---

## 6. Decisions kept

- One workplace at a time.
- Multi-chip cells, optimized for 0-2 chips because `max_shift_per_day` defaults to
  2 (`config/work.php:30`).
- Position picked per assignment.
- Hard-block validation at finalize, applied **per date** (D18): a date commits only
  if all its cells pass; valid dates are not blocked by a failing sibling date.
- Holiday shading dropped for v1.
