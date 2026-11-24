import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/shift-planning/index.page';

// ── hoisted mocks ─────────────────────────────────────────────────────────────

const { useShiftPlanningQueryMock, useSaveDraftMutationMock, useLockMutationMock } = vi.hoisted(() => ({
    useShiftPlanningQueryMock: vi.fn(),
    useSaveDraftMutationMock: vi.fn(),
    useLockMutationMock: vi.fn(),
}));

// ── module mocks ──────────────────────────────────────────────────────────────

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/dynamic', () => ({
    default: () => () => React.createElement('div', { 'data-testid': 'plan-grid' }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('jotai', () => ({
    atom: (initial: unknown) => ({ init: initial }),
    useAtom: () => [{ workplaces: [{ id: 1, name: 'Tokyo' }], workplace_access: {} }, vi.fn()],
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [], workplace_access: {} } },
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: vi.fn() },
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { 'type': 'button', onClick, 'aria-label': ariaLabel }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Chip: Object.assign(({ children, value }: any) => React.createElement('span', { 'data-value': value }, children), {
        Group: ({ children }: any) => React.createElement('div', null, children),
    }),
    CloseButton: ({ onClick, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { 'type': 'button', onClick, 'aria-label': ariaLabel }),
    Collapse: ({ children, expanded }: any) => (expanded ? React.createElement('div', null, children) : null),
    Divider: () => React.createElement('hr', null),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Popover: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        Target: ({ children }: any) => children,
        Dropdown: ({ children }: any) => React.createElement('div', { 'data-testid': 'popover' }, children),
    }),
    Select: ({ label, data, value, onChange, searchable: _s, w: _w }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement(
                'select',
                {
                    value: value ?? '',
                    onChange: (e: React.ChangeEvent<HTMLSelectElement>) => onChange?.(e.target.value),
                },
                (data ?? []).map((opt: any) =>
                    React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                ),
            ),
        ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ label, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                type: 'checkbox',
                checked,
                onChange: (e: React.ChangeEvent<HTMLInputElement>) => onChange?.(e),
            }),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ label, value, onChange, placeholder }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                type: 'text',
                value: value ?? '',
                placeholder,
                onChange: (e: React.ChangeEvent<HTMLInputElement>) => onChange?.(e),
            }),
        ),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => [initial, { open: vi.fn(), close: vi.fn(), toggle: vi.fn() }],
}));

vi.mock('@mantine/dates', () => ({
    MonthPickerInput: ({ label, value }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { type: 'text', readOnly: true, value: value ?? '' }),
        ),
}));

vi.mock('@/pages/manage/shift-planning/partials/api', () => ({
    useShiftPlanningQuery: (...args: unknown[]) => useShiftPlanningQueryMock(...args),
    useSaveDraftMutation: () => useSaveDraftMutationMock(),
    useFinalizeMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useLockMutation: (...args: unknown[]) => useLockMutationMock(...args),
    useReleaseLockMutation: () => ({ mutate: vi.fn() }),
    useCreateShiftMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useUpdateShiftConfigMutation: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('@/pages/manage/shift-planning/partials/data', () => ({
    buildCells: vi.fn(() => ({})),
    buildColumns: vi.fn(() => []),
    buildDraftPayload: vi.fn(() => []),
    cellKey: (id: number, date: string) => `${id}|${date}`,
    classifyChipChange: vi.fn(() => null),
    concreteShiftKeys: vi.fn(() => new Set<string>()),
    EMPTY_EMPLOYEE_FILTERS: { search: '', worktimeGroups: [], fixedScheduleOnly: false },
    isCellOccupied: vi.fn(() => false),
    newDraftChip: vi.fn((t: unknown) => ({ ...(t as object), id: 'draft-1', source: 'draft', action: 'add' })),
    positionName: vi.fn(() => 'Staff'),
    setRegistries: vi.fn(),
    shiftTimeLabel: vi.fn(() => '09:00–18:00'),
    toEmployees: vi.fn(() => []),
    toPositions: vi.fn(() => []),
    toShiftTypes: vi.fn(() => []),
    buildHeaderCounts: vi.fn(() => new Map()),
    splitShiftTypesByCreated: vi.fn(() => ({ created: [], notCreated: [] })),
    WORKTIME_GROUPS: ['morning', 'afternoon', 'night'],
    worktimeGroupLabel: vi.fn((g: string) => g),
}));

vi.mock('@/pages/manage/shift-planning/partials/plan_grid_skeleton', () => ({
    default: () => React.createElement('div', { 'data-testid': 'plan-grid-skeleton' }),
}));

vi.mock('@/pages/manage/shift-planning/partials/save_confirm_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/shift-planning/partials/validation_errors_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/shift-planning/partials/finalize_result_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/shift-planning/partials/create_shift_modal', () => ({
    default: () => null,
}));

// ── fixtures ──────────────────────────────────────────────────────────────────

const buildQueryReturn = (overrides: Record<string, unknown> = {}) => {
    const { data: dataOverride, ...restOverrides } = overrides;
    return {
        data: {
            workplace: { id: 1, name: 'Tokyo', min_rest_hours: null },
            window: { from: '2026-06-01', to: '2026-06-30', min_date: '2026-06-01', recent_shift_days: 60 },
            workplace_shifts: [],
            positions: [],
            members: [],
            shifts: [],
            live_assignments: [],
            drafts: [],
            lock: null,
            ...((dataOverride as object) ?? {}),
        },
        isLoading: false,
        dataUpdatedAt: Date.now(),
        refetch: vi.fn(),
        ...restOverrides,
    };
};

// ── tests ─────────────────────────────────────────────────────────────────────

describe('Shift planning page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        useShiftPlanningQueryMock.mockReturnValue(buildQueryReturn());
        useSaveDraftMutationMock.mockReturnValue({ mutate: vi.fn(), isPending: false });
        useLockMutationMock.mockReturnValue({ mutate: vi.fn(), isPending: false });
    });

    it('renders breadcrumbs and the Shift Planner heading', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');
        expect(screen.getAllByText('Shift Planner').length).toBeGreaterThan(0);
    });

    it('shows View only badge and Start editing button when no lock is held', () => {
        render(<IndexPage />);

        expect(screen.getByText('View only')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Start editing' })).toBeInTheDocument();
    });

    it('shows You are editing badge and Stop editing button when the user holds the lock', () => {
        useShiftPlanningQueryMock.mockReturnValue(
            buildQueryReturn({
                data: {
                    lock: { user_id: 1, name: 'Admin', is_me: true, acquired_at: '', last_activity_at: '', ttl: 300 },
                },
            }),
        );

        render(<IndexPage />);

        expect(screen.getByText('You are editing')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Stop editing' })).toBeInTheDocument();
    });

    it('shows Editing by [name] badge and Take over button when another user holds the lock', () => {
        useShiftPlanningQueryMock.mockReturnValue(
            buildQueryReturn({
                data: {
                    lock: { user_id: 2, name: 'Alice', is_me: false, acquired_at: '', last_activity_at: '', ttl: 300 },
                },
            }),
        );

        render(<IndexPage />);

        expect(screen.getByText('Editing by Alice')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Take over' })).toBeInTheDocument();
    });

    it('disables Save and Discard when no changes exist', () => {
        render(<IndexPage />);

        expect(screen.getByRole('button', { name: /Save changes/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Discard' })).toBeDisabled();
    });

    it('shows the planning grid when data has loaded', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('plan-grid')).toBeInTheDocument();
        expect(screen.queryByTestId('plan-grid-skeleton')).not.toBeInTheDocument();
    });

    it('shows the skeleton and hides the grid while data is loading', () => {
        useShiftPlanningQueryMock.mockReturnValue(buildQueryReturn({ isLoading: true, data: undefined }));

        render(<IndexPage />);

        expect(screen.getByTestId('plan-grid-skeleton')).toBeInTheDocument();
        expect(screen.queryByTestId('plan-grid')).not.toBeInTheDocument();
    });

    it('renders the Workplace selector with the option from master data', () => {
        render(<IndexPage />);

        expect(screen.getByRole('option', { name: 'Tokyo' })).toBeInTheDocument();
    });

    it('clicking Start editing calls the lock mutation', async () => {
        const lockMutateSpy = vi.fn();
        useLockMutationMock.mockReturnValue({ mutate: lockMutateSpy, isPending: false });
        const user = userEvent.setup();

        render(<IndexPage />);

        await user.click(screen.getByRole('button', { name: 'Start editing' }));

        expect(lockMutateSpy).toHaveBeenCalledWith(
            expect.objectContaining({ workplace_id: 1, force: false }),
            expect.any(Object),
        );
    });
});
