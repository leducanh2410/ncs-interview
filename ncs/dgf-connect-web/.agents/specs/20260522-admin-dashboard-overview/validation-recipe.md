# Dashboard Spec — Production-Data Validation Recipe

Reproducible steps to validate that the dashboard's KPIs and widgets actually help admins do workforce planning, by running the same aggregate queries the spec defines against real data.

Run this in a session that has shell access to a Docker stack of this repo where `dgf-connect-web-db-1` is a populated MySQL container (production dump or staging). Output is read-only, no schema mutations.

---

## Step 0 — Prerequisites

- `docker compose` stack up (`db`, `php`, `redis`, `soketi`).
- Database credentials: `root` / `root`, database `dgf_connect` (matches `backend/.env`).
- Repo working directory: `/home/<you>/.../dgf-connect-web` (the directory containing `docker-compose.yml`).
- Spec under review: `.agents/specs/20260522-admin-dashboard-overview/{requirements,design,decisions}.md`.

Sanity-check the container is up:

```bash
docker compose ps | grep db
```

Run all SQL through:

```bash
docker compose exec -T db mysql -uroot -proot dgf_connect -e "<SQL>"
```

The `-T` is required for non-TTY use inside an agent shell.

---

## Step 1 — Inspect the data landscape

**Goal:** confirm volumes, date range, key enum/flag distributions before running any "real" query. This is where you catch dead columns and pick the right "today" anchor.

```sql
SELECT 'workplaces' tbl, COUNT(*) n FROM workplaces UNION ALL
SELECT 'users',                COUNT(*) FROM users UNION ALL
SELECT 'workplace_shifts',     COUNT(*) FROM workplace_shifts UNION ALL
SELECT 'shifts',               COUNT(*) FROM shifts UNION ALL
SELECT 'shift_users',          COUNT(*) FROM shift_users UNION ALL
SELECT 'check_ins',            COUNT(*) FROM check_ins UNION ALL
SELECT 'shift_absent_notices', COUNT(*) FROM shift_absent_notices UNION ALL
SELECT 'shift_reports',        COUNT(*) FROM shift_reports;

SELECT MIN(date) min_shift_date, MAX(date) max_shift_date FROM shifts;
SELECT MIN(check_in_time) min_ci, MAX(check_in_time) max_ci FROM check_ins;

SELECT status, COUNT(*) c FROM shift_users GROUP BY status ORDER BY c DESC;
SELECT is_absent, COUNT(*) c FROM shift_users GROUP BY is_absent;
SELECT COUNT(*) reports, SUM(is_absent=1) absent_reports FROM shift_reports;
```

**What to record:**
- Volume of each table (sets expectation for query cost).
- `shifts.date` range and `check_ins.check_in_time` range — these define the usable window.
- `shift_users.status` values — confirm the "approved" enum literal matches `ShiftUserStatus`.
- `shift_users.is_absent` distribution — if `0` for all rows AND `shift_absent_notices` is empty, the spec's `excused_absence` branch is **dead code in this data** (flag this loud — it changes the meaning of every show-up / no-show number).
- `shift_reports.is_absent` distribution — this may be where historical absences actually live. If `shift_users.is_absent` and `shift_absent_notices` are empty but `shift_reports.is_absent` is populated, do **not** jump straight to "absence does not exist"; flag a spec/data-model mismatch.

### Check which date column the dashboard should use

The spec currently uses `shifts.date`, but the app also has `shifts.adjusted_date` and `workplace_shifts.date_adjustment`. Night shifts may belong to a different operational date depending on business meaning.

```sql
SELECT COUNT(*) shifted_dates
FROM shifts
WHERE adjusted_date <> date;

SELECT
  ws.date_adjustment,
  COUNT(*) shifts
FROM shifts s
JOIN workplace_shifts ws ON ws.id = s.workplace_shift_id
GROUP BY ws.date_adjustment
ORDER BY ws.date_adjustment;
```

**What to record:**
- If `adjusted_date <> date` appears often, ask the product/team whether dashboard grouping should use shift start date (`date`) or operational/payroll date (`adjusted_date`).
- Use the same chosen date column consistently in every later query. The examples below keep `s.date` because that is what the spec currently says.

### Choose the "today" anchor

The spec uses `today = CURDATE()` (Asia/Tokyo). In a frozen dump, that breaks: most of the next-14-day capacity range will have **no shifts**. Pick `@today` so the 14-day window stays inside the real data — typically:

```sql
SET @today := (
  SELECT DATE_SUB(DATE(MAX(check_in_time)), INTERVAL 15 DAY)
  FROM check_ins
);
-- e.g. if latest check-in is 2026-03-30 → @today = 2026-03-15
```

This gives the 14-row capacity table (`@today` through `@today + 13`) one full-day buffer before the latest check-in date. If the dump is known to be complete through the latest check-in date, `INTERVAL 14 DAY` is also acceptable. Record the chosen anchor and use it in every later query.

**Point-in-time caveat:** a production dump is not a historical snapshot. If you anchor `@today` in the past, current `shift_users.status`, reports, and shifts may include edits made after that anchor date. Treat the results as validation of metric shape/actionability, not an exact reconstruction of what admins saw on that day.

---

## Step 2 — Validate Active worker pool (KPI #1)

Spec: `|Active_Pool| = COUNT(DISTINCT user_id)` from `check_ins` over the window, scoped to Reliability_Workplaces. For validation we omit the workplace filter (= "global admin view") so we see the maximum signal.

```sql
SET @today := '<chosen anchor>';

SELECT '30d' window_label, COUNT(DISTINCT c.user_id) active_pool
FROM check_ins c JOIN shifts s ON s.id=c.shift_id
WHERE s.date >= DATE_SUB(@today, INTERVAL 30 DAY) AND s.date < @today
UNION ALL
SELECT '60d', COUNT(DISTINCT c.user_id)
FROM check_ins c JOIN shifts s ON s.id=c.shift_id
WHERE s.date >= DATE_SUB(@today, INTERVAL 60 DAY) AND s.date < @today
UNION ALL
SELECT '90d', COUNT(DISTINCT c.user_id)
FROM check_ins c JOIN shifts s ON s.id=c.shift_id
WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
UNION ALL
SELECT 'prior_90d (KPI delta)', COUNT(DISTINCT c.user_id)
FROM check_ins c JOIN shifts s ON s.id=c.shift_id
WHERE s.date >= DATE_SUB(@today, INTERVAL 180 DAY)
  AND s.date < DATE_SUB(@today, INTERVAL 90 DAY);
```

**Then check the frequency distribution** — the pool number lies if most users only checked in once:

```sql
SET @today := '<chosen anchor>';
SELECT
  CASE
    WHEN cnt = 1                 THEN '01 only'
    WHEN cnt BETWEEN 2 AND 4     THEN '02-04'
    WHEN cnt BETWEEN 5 AND 9     THEN '05-09'
    WHEN cnt BETWEEN 10 AND 19   THEN '10-19'
    WHEN cnt BETWEEN 20 AND 39   THEN '20-39'
    ELSE '40+'
  END bucket,
  COUNT(*) users
FROM (
  SELECT c.user_id, COUNT(*) cnt
  FROM check_ins c JOIN shifts s ON s.id=c.shift_id
  WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
  GROUP BY c.user_id
) t
GROUP BY bucket ORDER BY bucket;
```

**Judgement criteria:**
- Is the 90d:30d ratio plausible? A pool that doubles between 30d and 90d means there's a long tail of one-time workers — the KPI overstates "available labor".
- Is the prior-90d delta meaningful? A double-digit % swing is a real signal; a 1% swing is noise.
- What share of the pool checked in only once or twice? If >30%, recommend a tighter "active" definition (e.g. `≥4 check-ins in 30 days`).

---

## Step 3 — Validate Show-up / No-show rates (KPIs #2 and #4)

Spec: classify each `approved` `shift_users` row as `show` / `excused_absence` / `no_show` via `Outcome_Class`. Show_Up_Rate uses the dropdown window; No_Show_Rate is fixed at 30d.

```sql
SET @today := '<chosen anchor>';
SELECT
  '90d' window_label,
  COUNT(*) approved_assignments,
  SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) shows,
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=1 THEN 1 ELSE 0 END) excused,
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=0 THEN 1 ELSE 0 END) no_shows,
  ROUND(SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END)/COUNT(*)*100,2) show_up_pct,
  ROUND(SUM(CASE WHEN c.id IS NULL AND su.is_absent=0 THEN 1 ELSE 0 END)/COUNT(*)*100,2) no_show_pct
FROM shift_users su
JOIN shifts s ON s.id = su.shift_id
LEFT JOIN check_ins c ON c.shift_user_id = su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
UNION ALL
SELECT '30d (no-show KPI window)',
  COUNT(*),
  SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END),
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=1 THEN 1 ELSE 0 END),
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=0 THEN 1 ELSE 0 END),
  ROUND(SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END)/COUNT(*)*100,2),
  ROUND(SUM(CASE WHEN c.id IS NULL AND su.is_absent=0 THEN 1 ELSE 0 END)/COUNT(*)*100,2)
FROM shift_users su
JOIN shifts s ON s.id = su.shift_id
LEFT JOIN check_ins c ON c.shift_user_id = su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 30 DAY) AND s.date < @today
UNION ALL
SELECT 'prior 30d (no-show delta)',
  COUNT(*),
  SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END),
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=1 THEN 1 ELSE 0 END),
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=0 THEN 1 ELSE 0 END),
  ROUND(SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END)/COUNT(*)*100,2),
  ROUND(SUM(CASE WHEN c.id IS NULL AND su.is_absent=0 THEN 1 ELSE 0 END)/COUNT(*)*100,2)
FROM shift_users su
JOIN shifts s ON s.id = su.shift_id
LEFT JOIN check_ins c ON c.shift_user_id = su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 60 DAY)
  AND s.date < DATE_SUB(@today, INTERVAL 30 DAY);
```

Note: `shift_absent_notices` should be OR'd in for the `excused` classification, but it has been empty in every dump checked so far. If a future dump populates it, extend the `excused` predicate:

```sql
-- excused = check_in missing AND (is_absent OR EXISTS shift_absent_notices row)
SUM(CASE WHEN c.id IS NULL AND (su.is_absent=1
        OR EXISTS (SELECT 1 FROM shift_absent_notices san WHERE san.shift_user_id=su.id))
        THEN 1 ELSE 0 END) excused
```

**Then compare against `shift_reports.is_absent`** — this catches the case where the spec's absence sources are empty but the reporting table has historical absences:

```sql
SET @today := '<chosen anchor>';
SELECT
  COUNT(*) approved,
  SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) shows,
  SUM(CASE WHEN c.id IS NULL AND su.is_absent=0
            AND NOT EXISTS (SELECT 1 FROM shift_absent_notices san WHERE san.shift_user_id=su.id)
           THEN 1 ELSE 0 END) no_show_by_spec,
  SUM(CASE WHEN c.id IS NULL AND COALESCE(sr.is_absent,0)=1 THEN 1 ELSE 0 END) report_absent_without_checkin,
  SUM(CASE WHEN c.id IS NULL AND COALESCE(sr.is_absent,0)=0 THEN 1 ELSE 0 END) missing_unexcused_by_report,
  ROUND(SUM(CASE WHEN c.id IS NULL AND su.is_absent=0
                  AND NOT EXISTS (SELECT 1 FROM shift_absent_notices san WHERE san.shift_user_id=su.id)
                 THEN 1 ELSE 0 END)/COUNT(*)*100,2) no_show_pct_by_spec,
  ROUND(SUM(CASE WHEN c.id IS NULL AND COALESCE(sr.is_absent,0)=0
                 THEN 1 ELSE 0 END)/COUNT(*)*100,2) missing_unexcused_pct_by_report
FROM shift_users su
JOIN shifts s ON s.id = su.shift_id
LEFT JOIN check_ins c ON c.shift_user_id = su.id
LEFT JOIN shift_reports sr ON sr.shift_user_id = su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today;
```

If `no_show_by_spec` is mostly/all `report_absent_without_checkin`, the dashboard is not really measuring unexcused no-shows. Either revise `Outcome_Class` to include `shift_reports.is_absent`, or rename the metric to "missing check-in rate" so admins do not overinterpret it.

**Then check per-workplace variance** — the global % almost always looks fine; the planning signal is in the spread:

```sql
SET @today := '<chosen anchor>';
SELECT w.id wp_id, w.name workplace,
  COUNT(*) approved,
  SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) shows,
  ROUND(SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END)/COUNT(*)*100,2) show_up_pct
FROM shift_users su
JOIN shifts s ON s.id = su.shift_id
JOIN workplaces w ON w.id = s.workplace_id
LEFT JOIN check_ins c ON c.shift_user_id = su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
GROUP BY w.id, w.name
HAVING approved >= 10
ORDER BY show_up_pct ASC
LIMIT 15;
```

And the band distribution:

```sql
SET @today := '<chosen anchor>';
SELECT
  CASE
    WHEN show_up_pct < 50 THEN '00-49 critical'
    WHEN show_up_pct < 80 THEN '50-79 tight'
    WHEN show_up_pct < 95 THEN '80-94 ok'
    ELSE '95-100 healthy'
  END band,
  COUNT(*) workplaces, SUM(approved) approved_total
FROM (
  SELECT w.id, COUNT(*) approved,
    ROUND(SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END)/COUNT(*)*100,2) show_up_pct
  FROM shift_users su
  JOIN shifts s ON s.id = su.shift_id
  JOIN workplaces w ON w.id = s.workplace_id
  LEFT JOIN check_ins c ON c.shift_user_id = su.id
  WHERE su.status='approved'
    AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
  GROUP BY w.id
  HAVING approved >= 5
) t
GROUP BY band ORDER BY band;
```

**Judgement criteria:**
- Global show-up %: is the number high (>95%) and stable? If so, the headline KPI gives admins little to act on — the **drill-down** is where the value is.
- How many workplaces fall in `critical` (<50%) and `tight` (50–79%) bands? That count is the real "no-show risk" admins care about.
- Is the prior-30d delta nontrivial? If both 30d windows are within 0.5% of each other the delta arrow is decorative.
- Does `shift_reports.is_absent` change the no-show interpretation? If yes, this is a blocking definition issue for the KPI label and backend SQL.

---

## Step 4 — Validate Free bench / Capacity table (next 14 days)

Spec: capacity table = 14 day-rows; `free_bench(D) = max(0, |Active_Pool_90d| − distinct assigned on D)`; tag = `critical ≤ 10 / tight ≤ 30 / healthy > 30`.

```sql
SET @today := '<chosen anchor>';
SET @pool := (
  SELECT COUNT(DISTINCT c.user_id)
  FROM check_ins c JOIN shifts s ON s.id=c.shift_id
  WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
);
SELECT @pool active_pool_90d;

SELECT
  d.date,
  DAYOFWEEK(d.date) dow,
  COALESCE(a.assigned, 0) assigned,
  GREATEST(0, @pool - COALESCE(a.assigned, 0)) free_bench,
  CASE
    WHEN GREATEST(0, @pool - COALESCE(a.assigned, 0)) <= 10 THEN 'critical'
    WHEN GREATEST(0, @pool - COALESCE(a.assigned, 0)) <= 30 THEN 'tight'
    ELSE 'healthy'
  END capacity_tag
FROM (
  SELECT DATE_ADD(@today, INTERVAL n DAY) date
  FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
        UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13) seq
) d
LEFT JOIN (
  SELECT s.date, COUNT(DISTINCT su.user_id) assigned
  FROM shift_users su JOIN shifts s ON s.id=su.shift_id
  WHERE su.status='approved' GROUP BY s.date
) a ON a.date = d.date
ORDER BY d.date;
```

**Then a sensitivity check** — what would the table look like with a tighter pool and ratio thresholds?

```sql
SET @today := '<chosen anchor>';
SELECT 'pool: >=1 check-in 90d (spec)' label,
       (SELECT COUNT(DISTINCT user_id) FROM check_ins c JOIN shifts s ON s.id=c.shift_id
        WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today) n
UNION ALL SELECT 'pool: >=1 check-in 30d',
       (SELECT COUNT(DISTINCT user_id) FROM check_ins c JOIN shifts s ON s.id=c.shift_id
        WHERE s.date >= DATE_SUB(@today, INTERVAL 30 DAY) AND s.date < @today)
UNION ALL SELECT 'pool: >=1 check-in 14d',
       (SELECT COUNT(DISTINCT user_id) FROM check_ins c JOIN shifts s ON s.id=c.shift_id
        WHERE s.date >= DATE_SUB(@today, INTERVAL 14 DAY) AND s.date < @today)
UNION ALL SELECT 'pool: >=4 check-ins in 30d',
       (SELECT COUNT(*) FROM (
         SELECT c.user_id FROM check_ins c JOIN shifts s ON s.id=c.shift_id
         WHERE s.date >= DATE_SUB(@today, INTERVAL 30 DAY) AND s.date < @today
         GROUP BY c.user_id HAVING COUNT(*) >= 4) t)
UNION ALL SELECT 'avg distinct assigned per day (last 30d)',
       (SELECT ROUND(AVG(d)) FROM (
         SELECT s.date, COUNT(DISTINCT su.user_id) d
         FROM shift_users su JOIN shifts s ON s.id=su.shift_id
         WHERE su.status='approved' AND s.date >= DATE_SUB(@today, INTERVAL 30 DAY) AND s.date < @today
         GROUP BY s.date) x)
UNION ALL SELECT 'max distinct assigned in any single day (last 30d)',
       (SELECT MAX(d) FROM (
         SELECT s.date, COUNT(DISTINCT su.user_id) d
         FROM shift_users su JOIN shifts s ON s.id=su.shift_id
         WHERE su.status='approved' AND s.date >= DATE_SUB(@today, INTERVAL 30 DAY) AND s.date < @today
         GROUP BY s.date) x);
```

**Judgement criteria:**
- Does every row tag as `healthy`? If yes, the absolute thresholds (10/30) are out of scale — the widget has zero discrimination. Recommend ratio-based tags (e.g. critical ≥ 90% utilization).
- Does free_bench look like a fantasy number (~10× peak daily demand)? If so, the Active_Pool definition is too loose; reference Step 2's distribution to recommend a tighter "active" definition.
- Does any row have `assigned = 0`? Future shifts may simply not be in the dump — confirm by checking `MAX(shifts.date)` and `@today`.

**Then validate workplace-scoped capacity** — the dashboard has a workplace multi-select, so global capacity can be uselessly healthy while individual workplace scopes are tight, or tiny sites can become noisy under fixed thresholds.

```sql
SET @today := '<chosen anchor>';
WITH active AS (
  SELECT s.workplace_id, COUNT(DISTINCT c.user_id) active_pool
  FROM check_ins c
  JOIN shifts s ON s.id=c.shift_id
  WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
  GROUP BY s.workplace_id
),
assigned AS (
  SELECT s.workplace_id, s.date, COUNT(DISTINCT su.user_id) assigned
  FROM shift_users su
  JOIN shifts s ON s.id=su.shift_id
  WHERE su.status='approved'
    AND s.date >= @today AND s.date < DATE_ADD(@today, INTERVAL 14 DAY)
  GROUP BY s.workplace_id, s.date
),
dates AS (
  SELECT DATE_ADD(@today, INTERVAL n DAY) date
  FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
        UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13) seq
),
capacity AS (
  SELECT
    w.id workplace_id,
    w.name workplace,
    d.date,
    COALESCE(active.active_pool,0) active_pool,
    COALESCE(assigned.assigned,0) assigned,
    GREATEST(0, COALESCE(active.active_pool,0) - COALESCE(assigned.assigned,0)) free_bench
  FROM workplaces w
  JOIN dates d
  LEFT JOIN active ON active.workplace_id=w.id
  LEFT JOIN assigned ON assigned.workplace_id=w.id AND assigned.date=d.date
  WHERE COALESCE(active.active_pool,0) > 0 OR COALESCE(assigned.assigned,0) > 0
)
SELECT
  CASE
    WHEN free_bench <= 10 THEN 'critical'
    WHEN free_bench <= 30 THEN 'tight'
    ELSE 'healthy'
  END tag,
  COUNT(*) workplace_days,
  MIN(active_pool) min_pool,
  MAX(active_pool) max_pool,
  ROUND(AVG(active_pool),1) avg_pool
FROM capacity
GROUP BY tag
ORDER BY FIELD(tag,'critical','tight','healthy');
```

Sample the rows that drive the warning:

```sql
SET @today := '<chosen anchor>';
WITH active AS (
  SELECT s.workplace_id, COUNT(DISTINCT c.user_id) active_pool
  FROM check_ins c
  JOIN shifts s ON s.id=c.shift_id
  WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
  GROUP BY s.workplace_id
),
assigned AS (
  SELECT s.workplace_id, s.date, COUNT(DISTINCT su.user_id) assigned
  FROM shift_users su
  JOIN shifts s ON s.id=su.shift_id
  WHERE su.status='approved'
    AND s.date >= @today AND s.date < DATE_ADD(@today, INTERVAL 14 DAY)
  GROUP BY s.workplace_id, s.date
),
dates AS (
  SELECT DATE_ADD(@today, INTERVAL n DAY) date
  FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
        UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13) seq
),
capacity AS (
  SELECT
    w.id workplace_id,
    w.name workplace,
    d.date,
    COALESCE(active.active_pool,0) active_pool,
    COALESCE(assigned.assigned,0) assigned,
    GREATEST(0, COALESCE(active.active_pool,0) - COALESCE(assigned.assigned,0)) free_bench
  FROM workplaces w
  JOIN dates d
  LEFT JOIN active ON active.workplace_id=w.id
  LEFT JOIN assigned ON assigned.workplace_id=w.id AND assigned.date=d.date
  WHERE COALESCE(active.active_pool,0) > 0 OR COALESCE(assigned.assigned,0) > 0
)
SELECT workplace_id, workplace, date, active_pool, assigned, free_bench,
  CASE
    WHEN free_bench <= 10 THEN 'critical'
    WHEN free_bench <= 30 THEN 'tight'
    ELSE 'healthy'
  END tag
FROM capacity
ORDER BY free_bench ASC, assigned DESC
LIMIT 25;
```

**Additional judgement criteria:**
- If global capacity is always healthy but workplace-scoped capacity has critical/tight rows, the all-workplace widget needs clearer framing or default grouping.
- If most critical rows come from tiny active pools (e.g. `active_pool <= 5`), fixed thresholds are probably noisy; ratio thresholds and minimum-sample handling are safer.

---

## Step 5 — Validate Demand heatmap

Spec: rows = unique `start_time–end_time` slot labels used in Reliability_Workplaces over the window, columns = day-of-week, cell = average check-ins per occurrence.

```sql
-- How many slot labels would the heatmap have to display?
SELECT COUNT(DISTINCT CONCAT(TIME_FORMAT(start_time,'%H:%i'),'-',TIME_FORMAT(end_time,'%H:%i'))) total_slot_labels
FROM workplace_shifts;

SET @today := '<chosen anchor>';
SELECT COUNT(DISTINCT CONCAT(TIME_FORMAT(ws.start_time,'%H:%i'),'-',TIME_FORMAT(ws.end_time,'%H:%i'))) slots_used_90d
FROM shifts s JOIN workplace_shifts ws ON ws.id = s.workplace_shift_id
WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today;

-- Collision check: which slot labels are shared across many workplaces?
SELECT
  CONCAT(TIME_FORMAT(start_time,'%H:%i'),'-',TIME_FORMAT(end_time,'%H:%i')) slot_label,
  COUNT(DISTINCT workplace_id) workplaces, COUNT(*) slot_rows
FROM workplace_shifts
GROUP BY slot_label
ORDER BY workplaces DESC, slot_rows DESC
LIMIT 15;
```

Sample the actual heatmap output (top 10 slots by occurrence count):

```sql
SET @today := '<chosen anchor>';
SELECT slot_label,
  ROUND(AVG(CASE WHEN dow=2 THEN avg_ci END)) Mon,
  ROUND(AVG(CASE WHEN dow=3 THEN avg_ci END)) Tue,
  ROUND(AVG(CASE WHEN dow=4 THEN avg_ci END)) Wed,
  ROUND(AVG(CASE WHEN dow=5 THEN avg_ci END)) Thu,
  ROUND(AVG(CASE WHEN dow=6 THEN avg_ci END)) Fri,
  ROUND(AVG(CASE WHEN dow=7 THEN avg_ci END)) Sat,
  ROUND(AVG(CASE WHEN dow=1 THEN avg_ci END)) Sun
FROM (
  SELECT CONCAT(TIME_FORMAT(ws.start_time,'%H:%i'),'-',TIME_FORMAT(ws.end_time,'%H:%i')) slot_label,
    DAYOFWEEK(s.date) dow,
    COUNT(c.id) * 1.0 / COUNT(DISTINCT s.id) avg_ci,
    COUNT(DISTINCT s.id) occ
  FROM shifts s JOIN workplace_shifts ws ON ws.id = s.workplace_shift_id
  LEFT JOIN check_ins c ON c.shift_id = s.id
  WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
  GROUP BY slot_label, dow
) t
GROUP BY slot_label
HAVING SUM(occ) >= 50
ORDER BY SUM(occ) DESC
LIMIT 10;
```

**Judgement criteria:**
- How many slot labels are used in 90d? If >20, a flat heatmap is unscannable; recommend a top-N cap or workplace-scoping.
- Do the patterns make sense? E.g. weekend lift on `09:00–17:00`, night shifts (`22:00–06:00`) running 7 days. Real signal = the design is sound; flat-uniform cells = noise, the heatmap is decorative.
- Are popular slots (e.g. `17:00–22:00`) shared across 20+ workplaces? Confirms the spec's "collapse same labels across workplaces" rule is doing real work.

---

## Step 6 — Validate the workplace × slot drill-down

The most actionable view in the whole spec. Want to confirm it surfaces the specific (workplace, slot) failure points.

```sql
SET @today := '<chosen anchor>';
SELECT w.name workplace,
  CONCAT(TIME_FORMAT(ws.start_time,'%H:%i'),'-',TIME_FORMAT(ws.end_time,'%H:%i')) slot,
  COUNT(*) approved,
  SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) shows,
  ROUND(SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END)/COUNT(*)*100,1) show_up_pct
FROM shift_users su
JOIN shifts s ON s.id=su.shift_id
JOIN workplaces w ON w.id=s.workplace_id
JOIN workplace_shifts ws ON ws.id=s.workplace_shift_id
LEFT JOIN check_ins c ON c.shift_user_id=su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
GROUP BY w.id, w.name, ws.id
HAVING approved >= 5 AND show_up_pct < 80
ORDER BY show_up_pct ASC
LIMIT 15;
```

**Judgement criteria:**
- Does this surface specific bad cells (e.g. one workplace × one slot with <30% show-up)? If yes, the drill-down is the dashboard's strongest planning surface.
- Are there workplaces where ONE specific slot is bad but others are fine? That's a localized scheduling problem an admin can act on (move the shift, retrain, drop the slot).

---

## Step 7 — Validate realistic workplace selections

The global-admin view is useful for maximum signal, but the real dashboard has a workplace multi-select. Validate a few scoped selections so the verdict is not based only on the all-workplace aggregate.

Pick representative workplace IDs:

```sql
SET @today := '<chosen anchor>';

-- Largest workplaces by 90d approved assignment volume.
SELECT w.id, w.name, COUNT(*) approved
FROM shift_users su
JOIN shifts s ON s.id=su.shift_id
JOIN workplaces w ON w.id=s.workplace_id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
GROUP BY w.id, w.name
ORDER BY approved DESC
LIMIT 5;

-- Lowest-reliability workplaces with enough data to be meaningful.
SELECT w.id, w.name,
  COUNT(*) approved,
  SUM(c.id IS NOT NULL) shows,
  ROUND(SUM(c.id IS NOT NULL)/COUNT(*)*100,1) show_up_pct
FROM shift_users su
JOIN shifts s ON s.id=su.shift_id
JOIN workplaces w ON w.id=s.workplace_id
LEFT JOIN check_ins c ON c.shift_user_id=su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
GROUP BY w.id, w.name
HAVING approved >= 50
ORDER BY show_up_pct ASC
LIMIT 5;
```

Then rerun the KPI/capacity/heatmap/drill-down queries with a small set:

```sql
SET @workplace_ids := '1,2,3'; -- replace with chosen IDs
```

Add this predicate to every `shifts s` query:

```sql
AND FIND_IN_SET(s.workplace_id, @workplace_ids)
```

`FIND_IN_SET` is convenient for ad hoc validation. For performance checks and implementation SQL, use a real `IN (...)` predicate or bound IDs so MySQL can use indexes normally.

**Judgement criteria:**
- Do capacity tags become meaningful only after scoping to one or a few workplaces? If yes, the UI should not over-emphasize all-workplace capacity.
- Does heatmap row count drop enough for scoped selections? If no, top-N or coarse buckets are still needed.
- Does the KPI row tell a different story for low-reliability selections? If yes, consider surfacing "problem workplace count" globally and detailed rates when scoped.

---

## Step 8 — Check query performance

The spec requires each dashboard endpoint to respond within 500 ms p95 on realistic data. Run `EXPLAIN ANALYZE` on the heaviest query shapes before declaring the spec implementable without indexes.

Use the same `@today` and representative workplace filters from earlier steps. For each query, record wall-clock time, scan counts, and whether MySQL uses the expected indexes.

Examples:

```sql
SET @today := '<chosen anchor>';

EXPLAIN ANALYZE
SELECT w.id, w.name,
  COUNT(*) approved,
  SUM(c.id IS NOT NULL) shows,
  ROUND(SUM(c.id IS NOT NULL)/COUNT(*)*100,1) show_up_pct
FROM shift_users su
JOIN shifts s ON s.id=su.shift_id
JOIN workplaces w ON w.id=s.workplace_id
LEFT JOIN check_ins c ON c.shift_user_id=su.id
WHERE su.status='approved'
  AND s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
GROUP BY w.id, w.name
ORDER BY show_up_pct ASC;

EXPLAIN ANALYZE
SELECT CONCAT(TIME_FORMAT(ws.start_time,'%H:%i'),'-',TIME_FORMAT(ws.end_time,'%H:%i')) slot_label,
  DAYOFWEEK(s.date) dow,
  COUNT(c.id) * 1.0 / COUNT(DISTINCT s.id) avg_ci
FROM shifts s
JOIN workplace_shifts ws ON ws.id = s.workplace_shift_id
LEFT JOIN check_ins c ON c.shift_id = s.id
WHERE s.date >= DATE_SUB(@today, INTERVAL 90 DAY) AND s.date < @today
GROUP BY slot_label, dow;
```

**Judgement criteria:**
- If `EXPLAIN ANALYZE` shows full scans of `shift_users` or `check_ins` for every request, add composite index recommendations to the spec.
- Watch for queries that join `check_ins` by `shift_id`; the current schema has a unique index on `shift_user_id` and an index on `(user_id, check_in_time)`, but not necessarily the best index for heatmap-style aggregation.
- A query that is fast globally may still be slow with large `IN (...)` workplace filters; test both global and scoped cases.

---

## Step 9 — Write up the verdict

For each of the four widgets (KPI cards, capacity table, demand heatmap, show-up drill-down), record:

1. **What the data showed** — pasted result tables (or summarized counts).
2. **Does it discriminate?** — does the widget produce visibly different outputs across realistic inputs, or does it return the same answer everywhere (the capacity-tag problem)?
3. **Is it actionable?** — can an admin look at this and decide something (accept an order, retrain a workplace, move a shift)?
4. **Spec gaps surfaced** — any thresholds, definitions, or assumptions that the data invalidates. Flag dead code paths (e.g. `is_absent` always 0).

Final summary should answer the user's original question: **"do these stats actually help an admin see the overall picture and plan?"** Be specific about which widgets earn their place and which need redesign before shipping.

---

## Notes for future sessions

- **Don't trust the global show-up %** — it's almost always ~97%. The story is in the bottom of the distribution.
- **Anchor `@today` carefully.** If the dump is fresh, `CURDATE()` is fine. If frozen, anchor to `DATE(MAX(check_in_time)) - 15d` so the 14-row capacity table has a full-day buffer before the latest check-in date.
- **`is_absent` and `shift_absent_notices` may both be empty.** Check `shift_reports.is_absent` before concluding absences do not exist. If report absences explain missing check-ins, the spec's `Outcome_Class` source tables are wrong or the KPI label is wrong.
- **The Active_Pool definition is the lever** — it flows into Free_Bench, which flows into the capacity tag. If the pool is too loose (long tail of one-time workers), every downstream widget overstates capacity.
- **Slot-label cardinality grows with workplace count.** A 50-workplace dump may have a usable heatmap; a 200-workplace dump won't, unless the spec caps the visible rows.
