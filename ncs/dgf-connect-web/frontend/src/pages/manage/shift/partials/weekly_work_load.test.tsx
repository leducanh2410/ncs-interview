import React from 'react';
import { render, screen } from '@testing-library/react';
import dayjs from 'dayjs';
import { TFunction } from 'i18next';
import { describe, expect, it, vi } from 'vitest';
import {
    WeeklyWorkLoadBar,
    WeeklyWorkLoadProjection,
    describeDayHours,
    formatHours,
    getLimitSourceLabel,
    getWorkLoadColor,
    isAssignmentBlocked,
    isOverLimitAfterApproval,
    isPositionTarget,
} from '@/pages/manage/shift/partials/weekly_work_load';
import { ShiftWeeklyWorkLoad } from '@/pages/manage/shift/api';

const translate = (key: string, opts?: Record<string, unknown>) => {
    if (key === 'FORMAT_DATE_SHORT') return 'M/D';
    if (!opts) return key;
    return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
};
const t = translate as unknown as TFunction;

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: translate }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children, color }: any) =>
        React.createElement('span', { 'data-testid': 'badge', 'data-color': color }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Divider: () => React.createElement('hr', null),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Progress: ({ value, color }: any) =>
        React.createElement('div', { 'data-testid': 'progress', 'data-value': value, 'data-color': color }),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Tooltip: ({ children, label }: any) =>
        React.createElement('div', null, children, React.createElement('div', { 'data-testid': 'tooltip' }, label)),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parse: (value: string) => dayjs(value),
        parseAndFormat: (value: string) => dayjs(value).format('YYYY/MM/DD'),
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
}));

const buildWorkLoad = (overrides: Partial<ShiftWeeklyWorkLoad> = {}): ShiftWeeklyWorkLoad => ({
    user_id: 1,
    weekly_hours: 18,
    shift_hours: 9,
    is_shift_counted: true,
    projected_hours: 18,
    max_hour_per_week: 28,
    remaining_hours: 10,
    status: 'normal',
    is_blocked: false,
    blocked_over_hours: null,
    is_custom_limit: false,
    limit_start_date: null,
    limit_expiry_date: null,
    window_start: '2026-09-03',
    window_end: '2026-09-09',
    days: [
        { date: '2026-09-03', hours: 4, actual_hours: 4, scheduled_hours: 9 },
        { date: '2026-09-04', hours: 0, actual_hours: 0, scheduled_hours: 0 },
        { date: '2026-09-05', hours: 9, actual_hours: 0, scheduled_hours: 9 },
    ],
    ...overrides,
});

describe('weekly work load helpers', () => {
    it('formats hours without trailing zeros', () => {
        expect(formatHours(24.5, t)).toBe('24.5h');
        expect(formatHours(28, t)).toBe('28h');
        expect(formatHours(1 / 3, t)).toBe('0.33h');
        const japaneseT = ((key: string) => (key === 'h' ? '時間' : key)) as unknown as TFunction;
        expect(formatHours(28, japaneseT)).toBe('28時間');
    });

    it('maps statuses to colors and falls back to gray', () => {
        expect(getWorkLoadColor('normal')).toBe('teal');
        expect(getWorkLoadColor('near')).toBe('yellow');
        expect(getWorkLoadColor('over')).toBe('red');
        expect(getWorkLoadColor('unknown')).toBe('gray');
    });

    it('describes where the weekly limit comes from', () => {
        expect(getLimitSourceLabel(buildWorkLoad(), t)).toBe('Default config');
        expect(getLimitSourceLabel(buildWorkLoad({ is_custom_limit: true }), t)).toBe('Employee config (no period)');
        expect(
            getLimitSourceLabel(
                buildWorkLoad({
                    is_custom_limit: true,
                    limit_start_date: '2026-09-01',
                    limit_expiry_date: null,
                }),
                t,
            ),
        ).toBe('Employee config (2026/09/01 ~ )');
    });

    it('explains which hours of a day count toward the limit', () => {
        expect(describeDayHours({ date: '2026-09-01', hours: 7.5, actual_hours: 7.5, scheduled_hours: 8 }, t)).toBe(
            'Shift 8h · actual 7.5h → counted 7.5h',
        );
        expect(describeDayHours({ date: '2026-09-01', hours: 8, actual_hours: 0, scheduled_hours: 8 }, t)).toBe(
            'Shift 8h · not checked out → counted 8h',
        );
        expect(describeDayHours({ date: '2026-09-01', hours: 11.5, actual_hours: 7.5, scheduled_hours: 12 }, t)).toBe(
            'Shift 12h · actual 7.5h + not checked out 4h → counted 11.5h',
        );
    });

    it('treats any over-limit work load as blocked', () => {
        expect(isAssignmentBlocked(buildWorkLoad({ is_shift_counted: false, is_blocked: true }))).toBe(true);
        expect(isAssignmentBlocked(buildWorkLoad({ is_shift_counted: true, is_blocked: true }))).toBe(true);
        expect(isAssignmentBlocked(buildWorkLoad({ is_blocked: false }))).toBe(false);
        expect(isAssignmentBlocked(undefined)).toBe(false);
    });

    it('flags only approved members whose counted hours are over the limit', () => {
        expect(
            isOverLimitAfterApproval(buildWorkLoad({ is_shift_counted: true, is_blocked: false, status: 'over' })),
        ).toBe(true);
        expect(
            isOverLimitAfterApproval(buildWorkLoad({ is_shift_counted: true, is_blocked: false, status: 'near' })),
        ).toBe(false);
        expect(
            isOverLimitAfterApproval(buildWorkLoad({ is_shift_counted: false, is_blocked: true, status: 'over' })),
        ).toBe(false);
        expect(
            isOverLimitAfterApproval(buildWorkLoad({ is_shift_counted: false, is_blocked: false, status: 'over' })),
        ).toBe(false);
        expect(isOverLimitAfterApproval(undefined)).toBe(false);
    });

    it('treats every target except the register and rejected lists as a position', () => {
        expect(isPositionTarget('12')).toBe(true);
        expect(isPositionTarget('pending')).toBe(false);
        expect(isPositionTarget('rejected')).toBe(false);
    });
});

describe('WeeklyWorkLoadBar', () => {
    it('renders a dash when the work load is not loaded yet', () => {
        render(<WeeklyWorkLoadBar workLoad={undefined} />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('shows the total including this shift and what is left, with the same numbers in the tooltip', () => {
        render(<WeeklyWorkLoadBar workLoad={buildWorkLoad()} />);

        expect(screen.getByText('18h / 28h')).toBeInTheDocument();
        expect(screen.getAllByText('10h left')).toHaveLength(2);
        expect(screen.getByText('Total including this shift (9h): 18h / 28h')).toBeInTheDocument();
        expect(screen.getByText('7 days: 9/3 (Th) ~ 9/9 (We)')).toBeInTheDocument();
        expect(screen.getByTestId('progress')).toHaveAttribute('data-color', 'teal');
    });

    it('shows the blocking 7 days with the over amount and only the reject option when blocked', () => {
        render(
            <WeeklyWorkLoadBar
                workLoad={buildWorkLoad({
                    is_shift_counted: false,
                    weekly_hours: 24,
                    projected_hours: 32,
                    remaining_hours: -4,
                    status: 'over',
                    is_blocked: true,
                    blocked_over_hours: 4,
                    window_start: '2026-09-27',
                    window_end: '2026-10-03',
                })}
            />,
        );

        expect(screen.getByText('32h / 28h')).toBeInTheDocument();
        expect(screen.getByText('4h over')).toBeInTheDocument();
        expect(screen.getByText('Total including this shift (9h): 32h / 28h')).toBeInTheDocument();
        expect(screen.getByText('Over by 4h: can only be moved to the rejected list')).toBeInTheDocument();
        expect(screen.getByText('7 days: 9/27 (Su) ~ 10/3 (Sa)')).toBeInTheDocument();
        expect(screen.getByTestId('progress')).toHaveAttribute('data-value', '100');
        expect(screen.getByTestId('progress')).toHaveAttribute('data-color', 'red');
    });

    it('marks an employee-specific limit and breaks the 7 days down per day', () => {
        render(
            <WeeklyWorkLoadBar
                workLoad={buildWorkLoad({
                    max_hour_per_week: 40,
                    is_custom_limit: true,
                    limit_start_date: '2026-09-01',
                    limit_expiry_date: '2026-09-30',
                })}
            />,
        );

        expect(screen.getByText('18h / 40h*')).toBeInTheDocument();
        expect(screen.getByText('Shift 9h · actual 4h → counted 4h')).toBeInTheDocument();
        expect(screen.getByText('Shift 9h · not checked out → counted 9h')).toBeInTheDocument();
        expect(screen.getByText('Weekly limit: 40h (Employee config (2026/09/01 ~ 2026/09/30))')).toBeInTheDocument();
    });
});

describe('WeeklyWorkLoadProjection', () => {
    it('shows current and projected hours for someone not yet counted in the shift', () => {
        render(
            <WeeklyWorkLoadProjection
                workLoad={buildWorkLoad({
                    is_shift_counted: false,
                    weekly_hours: 18,
                    projected_hours: 27,
                    remaining_hours: 1,
                    status: 'near',
                })}
            />,
        );

        expect(screen.getByTestId('badge')).toHaveTextContent('18h → 27h / 28h');
        expect(screen.getByTestId('badge')).toHaveAttribute('data-color', 'yellow');
        expect(screen.getByText('1h left')).toBeInTheDocument();
    });

    it('shows only the total for someone already counted in the shift', () => {
        render(<WeeklyWorkLoadProjection workLoad={buildWorkLoad()} />);

        expect(screen.getByTestId('badge')).toHaveTextContent('18h / 28h');
    });

    it('turns red when the work load is blocked', () => {
        render(
            <WeeklyWorkLoadProjection
                workLoad={buildWorkLoad({ is_blocked: true, blocked_over_hours: 4, remaining_hours: -4 })}
            />,
        );

        expect(screen.getByTestId('badge')).toHaveAttribute('data-color', 'red');
        expect(screen.getByText('Over by 4h: can only be moved to the rejected list')).toBeInTheDocument();
    });
});
