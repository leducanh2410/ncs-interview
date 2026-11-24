import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PlanEmployee } from '@/pages/manage/shift-planning/partials/data';
import EmployeeInfo from '@/pages/manage/shift-planning/partials/employee_info';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ title }: any) => React.createElement('div', { title }),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

const buildEmployee = (overrides: Partial<PlanEmployee> = {}): PlanEmployee => ({
    id: 1,
    name: 'Yamada Taro',
    staffNo: '001',
    hasFixedSchedule: false,
    worktimeGroup: 'morning',
    fixedSchedules: [],
    absenceCount: 0,
    paperRejected: false,
    paperStatus: 'approved',
    expiringCards: [],
    recentShiftCount: 4,
    ...overrides,
});

describe('EmployeeInfo', () => {
    it('renders the name, staff no, worktime, and recent shift count', () => {
        render(<EmployeeInfo employee={buildEmployee()} recentDays={30} />);

        expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
        expect(screen.getByText('001')).toBeInTheDocument();
        expect(screen.getByText('Morning')).toBeInTheDocument();
        expect(screen.getByText('4 shifts')).toBeInTheDocument();
        expect(screen.getByText('in the last 30 days')).toBeInTheDocument();
    });

    it('shows a dash for staff no when empty', () => {
        render(<EmployeeInfo employee={buildEmployee({ staffNo: '' })} recentDays={30} />);

        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('shows the fixed-schedule dot and marker when hasFixedSchedule is true', () => {
        render(<EmployeeInfo employee={buildEmployee({ hasFixedSchedule: true })} recentDays={30} />);

        expect(screen.getByTitle('Fixed schedule')).toBeInTheDocument();
    });

    it('does not render a fixed schedule row when there are no fixed schedules', () => {
        render(<EmployeeInfo employee={buildEmployee()} recentDays={30} />);

        expect(screen.queryByText(/Mon|Tue|Wed|Thu|Fri|Sat|Sun/)).not.toBeInTheDocument();
    });

    it('renders fixed schedule lines when present', () => {
        render(
            <EmployeeInfo
                employee={buildEmployee({
                    fixedSchedules: [{ weekday: 1, start: '09:00', end: '18:00' }],
                })}
                recentDays={30}
            />,
        );

        expect(screen.getByText(/09:00-18:00/)).toBeInTheDocument();
    });

    it('renders no warnings when the employee has none', () => {
        render(<EmployeeInfo employee={buildEmployee()} recentDays={30} />);

        expect(screen.queryByText('Paper has been rejected')).not.toBeInTheDocument();
    });

    it('renders a paper-rejected warning', () => {
        render(<EmployeeInfo employee={buildEmployee({ paperRejected: true })} recentDays={30} />);

        expect(screen.getByText('Paper has been rejected')).toBeInTheDocument();
    });

    it.each([
        ['pending', 'Documents incomplete'],
        ['registered', 'Documents incomplete'],
        ['wait_for_approval', 'Documents awaiting approval'],
    ])('renders an incomplete documents warning when the papers are %s', (paperStatus, label) => {
        render(<EmployeeInfo employee={buildEmployee({ paperStatus })} recentDays={30} />);

        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('renders an expiring-cards warning', () => {
        render(<EmployeeInfo employee={buildEmployee({ expiringCards: ['Passport'] })} recentDays={30} />);

        expect(screen.getByText('Passport: expired or expiring soon')).toBeInTheDocument();
    });

    it('renders a recent-absences warning', () => {
        render(<EmployeeInfo employee={buildEmployee({ absenceCount: 2 })} recentDays={30} />);

        expect(screen.getByText('2 recent absences')).toBeInTheDocument();
    });
});
