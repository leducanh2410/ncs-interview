# Admin Dashboard Overview - Production Data Validation Results

Validated on 2026-05-22 against local MySQL container `dgf-connect-web-db-1`.

Data copy note: the user described the dump as production data until 2026-05-17. The database currently has `MAX(check_ins.check_in_time) = 2026-05-18 00:28:27`, so this write-up uses the validation recipe's frozen-data anchor:

```sql
@today = DATE(MAX(check_ins.check_in_time)) - INTERVAL 15 DAY = 2026-05-03
```

This keeps the next-14-day capacity window inside populated data.

## Mockup / Spec Metrics Reviewed

The mock dashboard page is currently fixture-driven and contains these metric surfaces:

- KPI row: active worker pool, average show-up rate, free bench next 7 days, no-show risk 30d.
- Capacity table: next 14 days, assigned users, free bench, capacity tag.
- Demand heatmap: shift-slot x day-of-week average check-ins.
- Show-up reliability: workplace rollup plus workplace x shift-slot drill-down.
- Forecast entry card: static navigation only, no metric.

## Data Landscape

| table | rows |
| --- | ---: |
| workplaces | 83 |
| users | 55,857 |
| workplace_shifts | 653 |
| shifts | 42,559 |
| shift_users | 682,152 |
| check_ins | 490,198 |
| shift_absent_notices | 874 |
| shift_reports | 514,334 |

Other checks:

- `shifts.date` range: 2025-03-01 to 2026-10-04.
- `check_ins.check_in_time` range: 2025-03-03 18:44:28 to 2026-05-18 00:28:27.
- `shift_users.status`: approved 519,207; pending 90,271; rejected 72,674.
- `shift_users.is_absent`: 95 true rows out of 682,152.
- `shift_reports.is_absent`: 50,706 absent reports out of 514,334 reports.
- `shifts.adjusted_date <> shifts.date`: 336 shifts.
- `workplace_shifts.date_adjustment`: -1 on 397 shifts, 0 on 41,721 shifts, +1 on 441 shifts.

Verdict: using `shifts.date` is probably acceptable for V1 because adjusted-date rows are under 2% of shifts, but the spec should explicitly confirm whether dashboards use calendar start date or operational/payroll date.

## KPI Validation

Active worker pool:

| window | active pool |
| --- | ---: |
| 30d | 5,316 |
| 60d | 6,461 |
| 90d | 6,810 |
| prior 90d | 10,600 |

90-day check-in frequency distribution:

| bucket | users |
| --- | ---: |
| 01 only | 804 |
| 02-04 | 1,215 |
| 05-09 | 1,144 |
| 10-19 | 1,457 |
| 20-39 | 1,632 |
| 40+ | 558 |

Show-up / no-show by the spec's current outcome definition:

| window | approved | shows | excused by `shift_users.is_absent` | no-shows by spec | show-up % | no-show % |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 90d | 109,103 | 105,585 | 18 | 3,500 | 96.78 | 3.21 |
| 30d | 40,752 | 39,016 | 12 | 1,724 | 95.74 | 4.23 |
| prior 30d | 37,767 | 36,742 | 6 | 1,019 | 97.29 | 2.70 |

Blocking definition issue:

| approved | shows | no-show by spec | report absent without check-in | missing unexcused by report |
| ---: | ---: | ---: | ---: | ---: |
| 109,103 | 105,585 | 3,498 | 3,518 | 0 |

Every missing check-in in the 90-day window is represented as `shift_reports.is_absent = 1`. The original spec's `Outcome_Class` sources (`shift_users.is_absent` or `shift_absent_notices`) did not match where production no-show data lives. This is resolved by the post-validation decision to use `shift_reports.is_absent` as the no-show source.

## Workplace Reliability

Per-workplace spread is real and actionable:

| band | workplaces | approved assignments |
| --- | ---: | ---: |
| 00-49 critical | 7 | 827 |
| 50-79 tight | 5 | 3,683 |
| 80-94 ok | 9 | 15,808 |
| 95-100 healthy | 27 | 88,781 |

Lowest meaningful workplaces by show-up rate include:

| workplace id | approved | shows | show-up % |
| ---: | ---: | ---: | ---: |
| 56 | 65 | 15 | 23.08 |
| 59 | 517 | 163 | 31.53 |
| 46 | 137 | 66 | 48.18 |
| 57 | 167 | 106 | 63.47 |
| 70 | 939 | 664 | 70.71 |

The workplace x slot drill-down is the strongest dashboard surface. It found concrete weak cells, for example:

| workplace / slot example | approved | shows | show-up % |
| --- | ---: | ---: | ---: |
| D15-NICHIREI / 13:00-17:30 | 6 | 0 | 0.0 |
| workplace 64 / 09:00-12:00 | 40 | 0 | 0.0 |
| D18-COOP OYAMA / 00:00-08:00 | 99 | 18 | 18.2 |
| D18-COOP OYAMA / 09:00-16:00 | 401 | 134 | 33.4 |

However, for the three lowest-reliability workplaces tested together (`56,59,46`), all 475 missing check-ins were `shift_reports.is_absent = 1`, with 0 missing-unexcused rows by report data.

## Capacity Validation

Global active pool 90d is 6,810. Global 14-day capacity using the spec formula:

| min assigned | max assigned | min free bench | max free bench | tags |
| ---: | ---: | ---: | ---: | --- |
| 728 | 2,287 | 4,523 | 6,082 | all healthy |

The global capacity KPI/table does not discriminate. Free bench is thousands of workers on every date.

Sensitivity:

| pool / demand measure | n |
| --- | ---: |
| pool: >=1 check-in 90d (spec) | 6,810 |
| pool: >=1 check-in 30d | 5,316 |
| pool: >=1 check-in 14d | 4,308 |
| pool: >=4 check-ins in 30d | 3,683 |
| avg distinct assigned per day last 30d | 1,349 |
| max distinct assigned in any single day last 30d | 1,857 |

Workplace-scoped capacity does discriminate:

| tag | workplace-days | min pool | max pool | avg pool |
| --- | ---: | ---: | ---: | ---: |
| critical | 203 | 0 | 11 | 4.0 |
| tight | 48 | 11 | 59 | 22.0 |
| healthy | 416 | 51 | 909 | 286.1 |

But the fixed thresholds are not well-scaled. Many critical rows are tiny pools, zero-pool rows, or sites with only a handful of assigned users. Ratio-based utilization and minimum sample handling would be safer than absolute `free_bench <= 10` / `<= 30`.

Scoped examples:

- Largest three workplaces (`41,5,6`): active pool 1,872, show-up 99.79%, capacity always healthy, 30 slots in heatmap.
- Low-reliability three workplaces (`56,59,46`): active pool 30, show-up 33.94%, capacity always tight, 8 slots in heatmap.

## Demand Heatmap Validation

Slot cardinality:

- Distinct slot labels overall: 217.
- Distinct slot labels used in 90d: 149.
- Common slot labels are shared broadly, e.g. `17:00-22:00` across 29 workplaces, `14:00-22:00` across 27, `22:00-06:00` across 23.

Top slot patterns are meaningful:

| slot | Mon | Tue | Wed | Thu | Fri | Sat | Sun | occurrences |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 17:00-22:00 | 23 | 21 | 19 | 21 | 28 | 17 | 13 | 753 |
| 14:00-22:00 | 23 | 19 | 21 | 22 | 26 | 17 | 15 | 694 |
| 22:00-06:00 | 22 | 21 | 20 | 22 | 24 | 15 | 13 | 567 |
| 09:00-17:00 | 4 | 4 | 4 | 3 | 3 | 8 | 6 | 512 |
| 09:00-18:00 | 14 | 12 | 10 | 11 | 24 | 29 | 21 | 224 |

Verdict: the heatmap has real demand shape, but a 149-row all-workplace heatmap is not scannable. It needs top-N, grouping, search/filtering, or should only be emphasized once workplaces are scoped.

## Performance Check

`EXPLAIN ANALYZE` on global show-up by workplace:

- Actual time: about 567 ms.
- Rows joined into aggregate: 109,103.
- Uses `shifts_workplace_date_index`, `shift_users_shift_id_user_id_unique`, and `check_ins_shift_user_id_unique`.

`EXPLAIN ANALYZE` on heatmap:

- Actual time: about 157 ms.
- Scans 42,559 shifts, filters to 10,269 90-day shifts, joins 106,577 check-in rows.
- Uses `check_ins_shift_id_foreign` for the check-in join.

Verdict: the heatmap query is fine at current volume. The reliability query already misses the spec's sub-500 ms target before HTTP/Laravel overhead, so the implementation should profile and likely add targeted composite indexes or reshape the query.

## Final Verdict

These stats can help an admin plan, but not exactly as originally specified.

Keep:

- Show-up by workplace and workplace x slot. This is the clearest planning signal.
- Demand heatmap, but only with row capping/grouping or strong workplace scoping.
- Workplace/window controls. The all-workplace view hides important differences.

Revise before shipping:

- No-show risk definition. Product clarification after validation: `shift_users.is_absent` is only an admin worktime-validation flag, and `shift_reports.is_absent` is the no-show source. The spec has been updated to use `shift_reports.is_absent` and ignore `shift_users.is_absent`.
- Global free bench / capacity tag. It is always healthy and not actionable at global scope.
- Capacity thresholds. Fixed 10/30 free-bench thresholds are out of scale globally and noisy for small sites; use utilization ratios and minimum samples.
- Active pool definition. `>=1 check-in in 90d` includes a long tail; a tighter definition such as recent or repeated check-ins would make capacity less inflated.

## Post-Validation Product Decisions

These decisions were applied to the spec after reviewing this validation output:

- Replace the global free-bench KPI with capacity pressure next 7 days: count of critical/tight workplace-days plus the worst workplace/date.
- Change capacity tags to ratio-based utilization: critical at 90% or higher, tight at 75% or higher, healthy below that; assigned rows with zero active pool are critical with a `no_pool` reason.
- Keep free bench as supporting detail on workplace/date rows, not as the global headline.
- Change the heatmap to use grouped time-of-day buckets by default and a capped Top Slots view for exact slot labels.
- Optimize the show-up endpoint by aggregating once at workplace-slot grain, rolling up workplace totals in PHP, filtering shifts date-first, and adding targeted composite indexes if EXPLAIN confirms the same production plan.
