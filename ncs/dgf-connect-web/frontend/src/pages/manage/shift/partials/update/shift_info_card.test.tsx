import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ShiftDetail } from '@/types/models/shift';
import ShiftInfoCard from '@/pages/manage/shift/partials/update/shift_info_card';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key) : key,
    }),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, component: Component, href }: any) =>
        Component ? React.createElement(Component, { href }, children) : React.createElement('a', { href }, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Card: ({ children }: any) => React.createElement('div', null, children),
    CardSection: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
        parseAndFormat: (value: string | null, _tz?: unknown, fmt?: string) =>
            value ? `FORMATTED(${value}${fmt ? `,${fmt}` : ''})` : '-',
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_enumObj: any, value: string) => `status:${value}`,
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_name: string, params: Record<string, unknown>) => `/admin/workplace/${params.id}`,
    },
}));

vi.mock('@/helpers/shift.helper', () => ({
    default: {
        getStatus: () => 'in_progress',
    },
}));

const buildShift = (overrides: Partial<ShiftDetail> = {}): ShiftDetail =>
    ({
        id: 1,
        date: '2026-06-26',
        salary_per_hour: 1200,
        work_hours: 7.5,
        created_at: '2026-06-01T00:00:00Z',
        updated_at: '2026-06-02T00:00:00Z',
        creator: undefined,
        fixed_count: 2,
        fixed_total: 3,
        fixed_pending_count: 0,
        temp_count: 1,
        temp_total: 2,
        temp_pending_count: 0,
        workplace: { id: 5, name: 'Tokyo HQ' },
        workplace_shift: {
            start_time: '09:00:00',
            end_time: '18:00:00',
            start_break_time: null,
            end_break_time: null,
        },
        ...overrides,
    }) as unknown as ShiftDetail;

describe('ShiftInfoCard', () => {
    it('renders workplace name linking to the workplace detail page', () => {
        render(<ShiftInfoCard shift={buildShift()} />);

        expect(screen.getByRole('link', { name: 'Tokyo HQ' })).toHaveAttribute('href', '/admin/workplace/5');
    });

    it('renders the formatted date and work-hour range', () => {
        render(<ShiftInfoCard shift={buildShift()} />);

        expect(screen.getByText('FORMATTED(2026-06-26) (09:00 ~ 18:00)')).toBeInTheDocument();
    });

    it('renders the shift work hours excluding break', () => {
        render(<ShiftInfoCard shift={buildShift()} />);

        expect(screen.getByText('Work hours')).toBeInTheDocument();
        expect(screen.getByText('7.5h (excluding break)')).toBeInTheDocument();
    });

    it('renders the translated status', () => {
        render(<ShiftInfoCard shift={buildShift()} />);

        expect(screen.getByText('status:in_progress')).toBeInTheDocument();
    });

    it('shows fixed/temp counts without a pending suffix when pending counts are 0', () => {
        render(<ShiftInfoCard shift={buildShift()} />);

        expect(screen.getByText('2 / 3')).toBeInTheDocument();
        expect(screen.getByText('1 / 2')).toBeInTheDocument();
    });

    it('appends the pending count suffix when there are pending fixed/temp employees', () => {
        render(<ShiftInfoCard shift={buildShift({ fixed_pending_count: 1, temp_pending_count: 2 })} />);

        expect(screen.getByText('2 / 3 (1 pending)')).toBeInTheDocument();
        expect(screen.getByText('1 / 2 (2 pending)')).toBeInTheDocument();
    });

    it('hides the overtime row when the shift does not count overtime by duration', () => {
        render(<ShiftInfoCard shift={buildShift({ is_overtime_by_duration: false })} />);

        expect(
            screen.queryByText(
                "Counted beyond this shift duration; time outside the shift schedule still requires the employee's overtime permission",
            ),
        ).not.toBeInTheDocument();
    });

    it('shows the overtime row when the shift counts overtime by duration', () => {
        render(<ShiftInfoCard shift={buildShift({ is_overtime_by_duration: true })} />);

        expect(
            screen.getByText(
                "Counted beyond this shift duration; time outside the shift schedule still requires the employee's overtime permission",
            ),
        ).toBeInTheDocument();
    });

    it('shows a dash for Closed By when there is no creator', () => {
        render(<ShiftInfoCard shift={buildShift({ creator: undefined })} />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('shows the creator name and formatted updated_at when a creator is present', () => {
        render(<ShiftInfoCard shift={buildShift({ creator: { name: 'Suzuki' } as any })} />);

        expect(screen.getByText(/Suzuki \(FORMATTED\(2026-06-02T00:00:00Z,FORMAT_TIMESTAMP\)\)/)).toBeInTheDocument();
    });

    it('disables Update config when canEdit is false', () => {
        render(<ShiftInfoCard shift={buildShift()} canEdit={false} />);

        expect(screen.getByRole('button', { name: 'Update config' })).toBeDisabled();
    });

    it('enables Update config when canEdit is true and calls onUpdateConfig when clicked', async () => {
        const user = userEvent.setup();
        const onUpdateConfig = vi.fn();
        render(<ShiftInfoCard shift={buildShift()} canEdit onUpdateConfig={onUpdateConfig} />);

        const button = screen.getByRole('button', { name: 'Update config' });
        expect(button).not.toBeDisabled();
        await user.click(button);

        expect(onUpdateConfig).toHaveBeenCalledTimes(1);
    });

    it('calls onExport when Export excel is clicked', async () => {
        const user = userEvent.setup();
        const onExport = vi.fn();
        render(<ShiftInfoCard shift={buildShift()} onExport={onExport} />);

        await user.click(screen.getByRole('button', { name: 'Export excel' }));

        expect(onExport).toHaveBeenCalledTimes(1);
    });
});
