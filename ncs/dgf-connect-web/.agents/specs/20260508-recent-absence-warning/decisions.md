---
name: Recent Absence Warning - Decisions
description: Clarification log and confirmed decisions for surfacing recent absence counts to mobile users and admins
type: project
---

# Decisions Log: Recent Absence Warning

## Overview

Surface a per-employee count of recent absences (true unannounced no-shows) so that (a) the mobile app can warn the employee after a successful shift registration and (b) the admin web app can warn admins via a hover-tooltip warning indicator on the add-user modal and the shift detail assignment table. The same definition and count are used by every consumer to keep the value consistent. Mobile UI changes live in a separate repo and are out of scope; this spec only delivers the API field that the mobile team will consume.

**Size: Medium.** Single coherent capability, two API surfaces (mobile registration + admin lists), one new shared backend service, two admin frontend touch points. No new persistent data, no new external interfaces.

## Questions and Answers

### Round 1: Scope and definition

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| What time window counts as "recent"? | Last 6 months, **configurable** via backend `.env` (default 180 days) | Use a rolling N-day window from "now" (server time). The duration is owned by the backend only — frontend never references it |
| How is an absence detected? | Derive purely from data: approved + ended + no `CheckIn` + no `shift_absent_notice` | Ignore the existing `shift_users.is_absent` flag for this calculation |
| What counts as "past"? | Shift `end_time` is on or before "the day before yesterday" — i.e., `end_time < start_of_yesterday` | Gives admins a buffer (yesterday and today) to record check-ins or notices before a shift starts counting as an absence |
| When should the warning appear? | Only when count ≥ 1 | No threshold beyond 1; no admin-configurable cutoff |

### Round 2: UI / API surfaces

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| How is the count returned to mobile and how does the popup behave? | Always include count in successful registration response; mobile shows a popup when count ≥ 1 every time. **Mobile UI is in a different repo and out of this spec's scope** | Backend-only deliverable for mobile: extend the registration response to include `absence_count` |
| Admin warning UI? | Warning icon next to the user name; hover tooltip shows count only (no duration) | Tooltip wording must not reference the duration so the configured value stays in one place (backend `.env`) |
| Add-user modal — where exactly? | Next to each user row in the search/result list | Search/list endpoint must return `absence_count` for every result |
| Per-request shape vs. separate endpoint? | Extend existing endpoints (shift detail assignment list, add-user search) to include `absence_count` per user | No new endpoint; one round-trip per page |

### Round 3: Shift detail coverage

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Where on the shift detail page should the warning appear? | Every assignment table on the page — pending/register list, rejected list, and all position groups | The shift detail page renders the shared `AssignmentTable` component four times. Wiring `AbsenceWarning` into the shared component covers all four sections automatically — no per-section gating |

### Round 4: Edge cases and performance

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Whose count is returned in the registration response? | The user's historical count up to "now"; the just-registered shift cannot itself be an absence (it's in the future) | Count is computed using the same rule for everything; no special exclusion needed for the just-registered shift |
| Include `absence_count` on registration error responses? | No — only on successful registration | Avoids unnecessary computation on validation failures |
| Should any users be excluded (terminated, on leave)? | No exclusions — count is purely the absence rule applied to whoever appears in the list | List-level filtering remains the existing endpoints' responsibility |
| Performance approach? | Single batched query per request; no caching | Compute via `shift_users` LEFT JOIN `check_ins` LEFT JOIN `shift_absent_notices`, aggregated by `user_id`. Batch must cover the union of users across all assignment-table instances on the shift detail page in one call |

## Confirmed Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Time window = trailing N days, configurable via backend `.env` (default 180) | Operations may want to tune the window without a code change; default reflects "6 months" | Round 1 |
| Frontend never references the duration | Keeps the value in exactly one place (backend `.env`); no risk of frontend/backend drift | Round 1 |
| Absence rule: `shift_users.status = approved` AND `shifts.end_time < (today − 1 day) at 00:00` AND no `check_ins` row AND no `shift_absent_notices` row | Matches the user-given definition; derived from existing tables only — no new persistent state | Round 1 |
| Two-day grace period before a shift's end is counted | Allows admins to retroactively record check-ins or process absent notices before the shift counts as a no-show | Round 1 |
| `is_absent` flag is **not** part of the new computation | The new rule is the canonical definition of "true unannounced no-show"; existing `is_absent` semantics (admin toggle, post-shift report) are separate and unchanged | Round 1 |
| Threshold: show warning only when `absence_count ≥ 1` | Simplest, no configuration needed | Round 1 |
| Single source of truth: one backend action computes counts for one or many users | Mobile API, admin search, and shift detail must agree | Architectural decision (see design) |
| `absence_count` returned as a per-user integer field on extended endpoints | Avoids extra round-trips and keeps the shape uniform | Round 2 |
| Mobile registration: returns `absence_count` only on success; mobile UI implementation is out of scope (separate repo) | Backend ships the field; mobile team implements the popup | Round 2 |
| Admin UI: warning triangle icon + hover tooltip, shown only when `absence_count ≥ 1` | Compact, non-intrusive, matches existing Mantine conventions | Round 2 |
| Tooltip wording shows count only — no duration | Avoids duplicating the configured window in frontend translations | Round 2 |
| No exclusion of terminated / on-leave users | Out of scope for this feature | Round 3 |
| Computation is per-request, no cache, single batched query | Avoids freshness issues and a job dependency; expected list sizes are small | Round 3 |
| No breaking changes to existing data — `is_absent` and `shift_absent_notices` rows untouched | Stated in feature description | Feature description |

## Open Questions

| Question | Blocking? | Notes |
|----------|-----------|-------|
| (none) | — | All blocking unknowns resolved |

## Assumptions

| Assumption | Validation Needed | Impact |
|------------|-------------------|--------|
| The mobile app's registration response is consumed only by the mobile client; no other consumer relies on its current shape | Light — confirm with mobile team before merging | If another consumer exists, the additive `absence_count` field is still backward-compatible (no field renames or removals) |
| `shifts.end_time` is a stored timestamp (not derived) and is set on every approved shift | Verified during exploration | Required for the date filter in the absence query |
| The existing add-user search endpoint returns user records (not just IDs) | Verified during exploration (assignment table loads enriched user data) | Required to attach `absence_count` per result |
| Server time zone is the operative cutoff for "the day before yesterday" — same JST/UTC convention used elsewhere in the system | Light — confirm during implementation | Off-by-one risk near midnight; mitigate by using the same `Carbon::today()` style used by existing date-window queries |
