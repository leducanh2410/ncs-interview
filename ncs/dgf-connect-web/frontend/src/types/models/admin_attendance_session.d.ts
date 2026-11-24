import { AdminAttendanceEvent } from '@/types/models/admin_attendance_event';
import { AdminAttendanceInterval } from '@/types/models/admin_attendance_interval';
import { User } from '@/types/models/user';
import { Workplace } from '@/types/models/workplace';

export interface AdminAttendanceSession {
    id: number;
    user_id: number;
    workplace_id: number;
    work_date: string;
    first_check_in_at: string;
    last_check_out_at: string | null;
    work_minutes: number | null;
    work_hours: string;
    has_missing_checkout: boolean;
    // Set once an unclosed check-in's Valid_Checkout_Window has elapsed and the next check-in/
    // check-out request auto-resolved it; null for every session unaffected by this.
    invalidated_reason: string | null;
    // The absolute deadline for closing this session, computed from its latest check-in event
    // (not `first_check_in_at` if the admin re-checked in after a check-out) — see
    // AdminAttendanceService::checkoutDeadlineFor(). Always present; recomputed on serialization.
    checkout_deadline_at: string;
    // True when that same latest check-in qualified for the overnight (08:00-next-day) branch of
    // the Valid_Checkout_Window, as opposed to the same-day-only (00:00-next-day) branch.
    is_overnight: boolean;
    user?: User;
    workplace?: Workplace;
    events?: AdminAttendanceEvent[];
    // Valid, credited Attendance_Intervals only (spec Requirement 8) — an interrupted or
    // still-open span never appears here, only in `events`.
    intervals?: AdminAttendanceInterval[];
}

// Level 1 — one row per (admin, day).
export interface AdminAttendanceDailySummary {
    user_id: number;
    work_date: string;
    user?: User;
    workplace_count: number;
    total_hours: string;
    has_missing_checkout: boolean;
    // True when the day contains at least one invalidated session — kept separate from
    // has_missing_checkout so excluding an invalidated session from that count doesn't make the
    // day appear anomaly-free.
    has_invalidated_session: boolean;
}
