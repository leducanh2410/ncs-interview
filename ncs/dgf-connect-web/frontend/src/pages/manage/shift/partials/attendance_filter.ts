/**
 * Pure URL-scoping helpers for the shift detail page's attendance filter. Deliberately free
 * of React/Mantine/i18n imports: AssignmentTable's own module transitively pulls in
 * FormHelper/NotificationHelper/DateHelper (and, through them, the real i18n singleton),
 * which don't play well with `vi.importActual` inside a test file that partially mocks
 * 'react-i18next' for unrelated reasons. Keeping this logic here lets tests exercise the
 * real implementation instead of a hand-rolled reimplementation that can silently drift
 * from production behavior.
 */

export type AttendanceFilter = 'all' | 'unconfirmed' | 'not_checked_in';

const ATTENDANCE_FILTER_VALUES: AttendanceFilter[] = ['all', 'unconfirmed', 'not_checked_in'];

export const parseAttendanceFilter = (value: unknown): AttendanceFilter => {
    return typeof value === 'string' && (ATTENDANCE_FILTER_VALUES as string[]).includes(value)
        ? (value as AttendanceFilter)
        : 'all';
};

export const parseAttendanceGroup = (value: unknown): string | null => (typeof value === 'string' ? value : null);

/**
 * Stable identity for URL-scoping the attendance filter, independent of the shift detail
 * page's split-by-position/combined view toggle. The approved roster keeps ONE scope
 * ('approved') whether it's currently rendered as several per-position tables or one
 * combined table, so a link written in one view mode still seeds correctly in the other.
 */
export type AttendanceScope = 'pending' | 'approved' | 'rejected';

/**
 * Resolves the AssignmentTable-scoped seed value for `initialAttendanceFilter`.
 *
 * The shift detail page renders several AssignmentTable instances (register list,
 * per-position or combined approved tables, rejected list) that would otherwise all read
 * the same ?attendance= value. Tagging a write with the originating table's AttendanceScope
 * (via ?attendanceGroup=) lets only the matching table apply it; every other table gets
 * `undefined`, meaning "leave local state alone" (see the `initialAttendanceFilter !==
 * undefined` guard in AssignmentTable).
 *
 * A bare ?attendance= with no ?attendanceGroup= is the original, table-agnostic contract
 * (spec Requirement 4.7: "the shift detail URL carries an attendance query parameter") and
 * is honored everywhere, so an externally-crafted or legacy link still works rather than
 * being silently ignored because it doesn't name a scope.
 */
export const resolveInitialAttendanceFilter = (
    attendanceQuery: unknown,
    attendanceGroupQuery: unknown,
    scope: AttendanceScope,
): AttendanceFilter | undefined => {
    const group = parseAttendanceGroup(attendanceGroupQuery);
    if (group === null) {
        return typeof attendanceQuery === 'string' ? parseAttendanceFilter(attendanceQuery) : undefined;
    }
    return group === scope ? parseAttendanceFilter(attendanceQuery) : undefined;
};
