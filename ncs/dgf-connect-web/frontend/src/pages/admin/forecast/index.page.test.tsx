import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ForecastPage from '@/pages/admin/forecast/index.page';
import { ForecastResponse } from '@/pages/admin/forecast/types';

const { useForecastOptionsQueryMock, useGenerateForecastMutationMock } = vi.hoisted(() => ({
    useForecastOptionsQueryMock: vi.fn(),
    useGenerateForecastMutationMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;
            return Object.entries(options).reduce((acc, [k, v]) => acc.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => {
    const ScrollArea: any = ({ children }: any) => React.createElement('div', null, children);
    ScrollArea.Autosize = ({ children }: any) => React.createElement('div', null, children);

    const Table: any = ({ children }: any) => React.createElement('table', null, children);
    Table.Thead = ({ children }: any) => React.createElement('thead', null, children);
    Table.Tbody = ({ children }: any) => React.createElement('tbody', null, children);
    Table.Tr = ({ children }: any) => React.createElement('tr', null, children);
    Table.Th = ({ children }: any) => React.createElement('th', null, children);
    Table.Td = ({ children }: any) => React.createElement('td', null, children);

    return {
        ActionIcon: ({ children }: any) => React.createElement('button', { type: 'button' }, children),
        Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
        Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
        Badge: ({ children }: any) => React.createElement('span', null, children),
        Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
        Button: ({ children, type, loading, disabled, onClick }: any) =>
            React.createElement('button', { type: type ?? 'button', disabled: disabled || loading, onClick }, children),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Group: ({ children }: any) => React.createElement('div', null, children),
        Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
        NumberInput: ({ label, value, onChange }: any) =>
            React.createElement(
                'label',
                null,
                label,
                React.createElement('input', { 'type': 'number', 'aria-label': label, 'value': value ?? '', onChange }),
            ),
        Paper: ({ children }: any) => React.createElement('div', null, children),
        Progress: ({ value }: any) => React.createElement('div', { 'role': 'progressbar', 'aria-valuenow': value }),
        ScrollArea,
        Select: ({ label, value, onChange, disabled }: any) =>
            React.createElement(
                'label',
                null,
                label,
                React.createElement('select', { 'aria-label': label, 'value': value ?? '', onChange, disabled }),
            ),
        SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
        Stack: ({ children }: any) => React.createElement('div', null, children),
        Table,
        Text: ({ children }: any) => React.createElement('span', null, children),
        TextInput: ({ placeholder, value, onChange }: any) =>
            React.createElement('input', { placeholder, value: value ?? '', onChange }),
        Title: ({ children }: any) => React.createElement('h1', null, children),
        Tooltip: ({ children }: any) => React.createElement('span', null, children),
    };
});

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { 'aria-label': label, 'type': 'text' }),
        ),
}));

const FORM_VALUES = {
    workplace_id: '1',
    workplace_shift_id: '10',
    date: '2026-07-01',
    requested_count: 5,
    radius_km: 10,
};

vi.mock('@mantine/form', () => ({
    useForm: () => ({
        values: FORM_VALUES,
        onSubmit: (handler: any) => (e: any) => {
            e?.preventDefault?.();
            return handler(FORM_VALUES);
        },
        getInputProps: (_field: string) => ({ value: '', onChange: () => {}, error: null }),
        setFieldValue: vi.fn(),
        errors: {},
    }),
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

vi.mock('@/helpers/date.helper', () => ({
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
    default: {
        now: () => ({
            add: () => ({ format: () => '2026-07-03', toDate: () => new Date() }),
            toDate: () => new Date(),
        }),
        format: (_d: Date, _f: string) => '2026-07-03',
        parseAndFormat: (value: string) => value,
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        toSelectOptions: (workplaces: any[]) => workplaces.map((w: any) => ({ value: w.id.toString(), label: w.name })),
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showError: vi.fn(),
        showSuccess: vi.fn(),
    },
}));

vi.mock('@/pages/admin/forecast/api', () => ({
    useForecastOptionsQuery: (...args: unknown[]) => useForecastOptionsQueryMock(...args),
    useGenerateForecastMutation: (...args: unknown[]) => useGenerateForecastMutationMock(...args),
}));

const buildWorkplace = (id = 1) => ({
    id,
    name: `Warehouse ${id}`,
    is_test: false,
    shifts: [
        {
            id: 10,
            workplace_id: id,
            start_time: '09:00',
            end_time: '18:00',
            date_adjustment: 0,
            label: '09:00 - 18:00',
        },
    ],
});

const buildForecastResponse = (): ForecastResponse => ({
    input: {
        workplace_id: 1,
        workplace_shift_id: 10,
        date: '2026-07-01',
        day_of_week: 2,
        requested_count: 5,
        shift_start: '2026-07-01 09:00:00',
        shift_end: '2026-07-01 18:00:00',
        lead_time_hours: 120,
    },
    recommendation: {
        decision: 'accept',
        risk_level: 'low',
        confidence: 'high',
        safe_commitment: 5,
        expected_attendance: 4,
        expected_shortage: 1,
        suggested_approval_target: 6,
        reason_codes: [],
    },
    demand_context: {
        recent_1_year: {
            all_weekdays: {
                shift_count: 10,
                avg_requested: 5,
                avg_approved: 4,
                avg_check_in: 3,
                avg_fill_rate: 80,
                avg_show_up_rate: 75,
            },
            same_weekday: {
                shift_count: 5,
                avg_requested: 5,
                avg_approved: 4,
                avg_check_in: 3,
                avg_fill_rate: 80,
                avg_show_up_rate: 75,
            },
        },
        last_3_months: {
            all_weekdays: {
                shift_count: 3,
                avg_requested: 5,
                avg_approved: 4,
                avg_check_in: 3,
                avg_fill_rate: 80,
                avg_show_up_rate: 75,
            },
            same_weekday: {
                shift_count: 1,
                avg_requested: 5,
                avg_approved: 4,
                avg_check_in: 3,
                avg_fill_rate: 80,
                avg_show_up_rate: 75,
            },
        },
        same_month_last_year: {
            all_weekdays: {
                shift_count: 2,
                avg_requested: 5,
                avg_approved: 4,
                avg_check_in: 3,
                avg_fill_rate: 80,
                avg_show_up_rate: 75,
            },
            same_weekday: {
                shift_count: 1,
                avg_requested: 5,
                avg_approved: 4,
                avg_check_in: 3,
                avg_fill_rate: 80,
                avg_show_up_rate: 75,
            },
        },
    },
    reliability: {
        source: 'exact_shift',
        sample_size: 20,
        approved_count: 50,
        check_in_count: 40,
        show_up_rate: 80.0,
        no_show_rate: 20.0,
        absent_notice_rate: 5.0,
        confidence: 'high',
    },
    fill_speed: {
        checkpoints: [
            {
                key: 'd_14',
                label: 'D-14',
                sample_size: 10,
                avg_approved_count: 3,
                average_registered_rate: 60,
                average_fill_rate: 50,
                shortage_rate: 30,
            },
            {
                key: 'start',
                label: 'Start',
                sample_size: 10,
                avg_approved_count: 5,
                average_registered_rate: 100,
                average_fill_rate: 90,
                shortage_rate: 5,
            },
        ],
        average_hours_before_start_to_target: 48,
        reach_target_rate: 85,
        shortage_h6_rate: 10,
        shortage_h2_rate: 5,
        emergency_add_rate: 8,
        avg_emergency_add_count: 0.5,
    },
    pool: {
        radius_km: 10,
        nearby_workplace_count: 2,
        eligible_workplace_members: 100,
        active_90_day_pool: 30,
        active_365_day_pool: 50,
        fixed_schedule_matches: 5,
        already_assigned_same_adjusted_date: 2,
        available_pool_estimate: 28,
        available_extended_pool: 48,
    },
    candidates: [
        {
            user_id: 1,
            name: 'Taro Yamada',
            staff_no: 'EMP001',
            score: 75,
            opportunity_days: 20,
            worked_days: 15,
            target_days: 10,
            nearby_days: 0,
            last_worked_date: '2026-06-20',
            fixed_schedule: false,
            confidence: 'high',
        },
        {
            user_id: 2,
            name: 'Hanako Sato',
            staff_no: 'EMP002',
            score: 50,
            opportunity_days: 20,
            worked_days: 10,
            target_days: 10,
            nearby_days: 2,
            last_worked_date: '2026-06-18',
            fixed_schedule: true,
            confidence: 'medium',
        },
    ],
    warnings: [],
});

describe('Forecast page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useForecastOptionsQueryMock.mockReturnValue({
            data: { data: { workplaces: [buildWorkplace()] } },
            isLoading: false,
        });

        useGenerateForecastMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue({ data: buildForecastResponse() }),
            isPending: false,
        });
    });

    it('shows a loader while options are loading', () => {
        useForecastOptionsQueryMock.mockReturnValue({ data: undefined, isLoading: true });

        render(<ForecastPage />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('shows no-workplaces alert when options return empty list', () => {
        useForecastOptionsQueryMock.mockReturnValue({ data: { data: { workplaces: [] } }, isLoading: false });

        render(<ForecastPage />);

        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByText('No accessible workplaces')).toBeInTheDocument();
    });

    it('does not show the loader or the alert when workplaces are available', () => {
        render(<ForecastPage />);

        expect(screen.queryByTestId('loader')).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('renders form labels for all input fields', () => {
        render(<ForecastPage />);

        expect(screen.getByText('Workplace')).toBeInTheDocument();
        expect(screen.getByText('Shift')).toBeInTheDocument();
        expect(screen.getByText('Date')).toBeInTheDocument();
        expect(screen.getByText('Requested employees')).toBeInTheDocument();
        expect(screen.getByText('Nearby radius (km)')).toBeInTheDocument();
    });

    it('run forecast button is disabled when workplaces list is empty', () => {
        useForecastOptionsQueryMock.mockReturnValue({ data: { data: { workplaces: [] } }, isLoading: false });

        render(<ForecastPage />);

        expect(screen.getByRole('button', { name: 'Run forecast' })).toBeDisabled();
    });

    it('run forecast button is enabled when workplaces are available', () => {
        render(<ForecastPage />);

        expect(screen.getByRole('button', { name: 'Run forecast' })).not.toBeDisabled();
    });

    it('shows recommendation section after successful forecast submission', async () => {
        const user = userEvent.setup();

        render(<ForecastPage />);

        await user.click(screen.getByRole('button', { name: 'Run forecast' }));

        await waitFor(() => {
            expect(screen.getByText('Recommendation')).toBeInTheDocument();
        });

        expect(screen.getByText('Accept requested count')).toBeInTheDocument();
        expect(screen.getByText('Low risk')).toBeInTheDocument();
    });

    it('shows candidate table after forecast with correct count', async () => {
        const user = userEvent.setup();

        render(<ForecastPage />);

        await user.click(screen.getByRole('button', { name: 'Run forecast' }));

        await waitFor(() => {
            expect(screen.getByText('Candidate employees')).toBeInTheDocument();
        });

        expect(screen.getByText('Taro Yamada')).toBeInTheDocument();
        expect(screen.getByText('Hanako Sato')).toBeInTheDocument();
    });

    it('candidate search filters rows by name', async () => {
        const user = userEvent.setup();

        render(<ForecastPage />);

        await user.click(screen.getByRole('button', { name: 'Run forecast' }));

        await waitFor(() => {
            expect(screen.getByText('Taro Yamada')).toBeInTheDocument();
        });

        const searchInput = screen.getByPlaceholderText('Search by name or staff no');
        await user.type(searchInput, 'Taro');

        expect(screen.getByText('Taro Yamada')).toBeInTheDocument();
        expect(screen.queryByText('Hanako Sato')).not.toBeInTheDocument();
    });

    it('candidate search filters rows by staff no', async () => {
        const user = userEvent.setup();

        render(<ForecastPage />);

        await user.click(screen.getByRole('button', { name: 'Run forecast' }));

        await waitFor(() => {
            expect(screen.getByText('Taro Yamada')).toBeInTheDocument();
        });

        const searchInput = screen.getByPlaceholderText('Search by name or staff no');
        await user.type(searchInput, 'EMP002');

        expect(screen.queryByText('Taro Yamada')).not.toBeInTheDocument();
        expect(screen.getByText('Hanako Sato')).toBeInTheDocument();
    });

    it('shows no candidates message when list is empty', async () => {
        useGenerateForecastMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue({ data: { ...buildForecastResponse(), candidates: [] } }),
            isPending: false,
        });

        const user = userEvent.setup();

        render(<ForecastPage />);

        await user.click(screen.getByRole('button', { name: 'Run forecast' }));

        await waitFor(() => {
            expect(screen.getByText('No callable candidates found')).toBeInTheDocument();
        });
    });

    it('shows demand context section after forecast', async () => {
        const user = userEvent.setup();

        render(<ForecastPage />);

        await user.click(screen.getByRole('button', { name: 'Run forecast' }));

        await waitFor(() => {
            expect(screen.getByText('Demand context')).toBeInTheDocument();
        });

        expect(screen.getAllByText('Recent 1 year').length).toBeGreaterThanOrEqual(1);
        expect(screen.getAllByText('Last 3 months').length).toBeGreaterThanOrEqual(1);
    });

    it('shows forecast page title', () => {
        render(<ForecastPage />);

        expect(screen.getByRole('heading', { name: 'Forecast' })).toBeInTheDocument();
    });
});
