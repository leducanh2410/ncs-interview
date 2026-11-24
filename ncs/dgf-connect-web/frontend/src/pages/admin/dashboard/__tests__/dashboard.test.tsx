import React from 'react';
import { MantineProvider } from '@mantine/core';
import { DatesProvider } from '@mantine/dates';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RouteConfig from '@/configs/route.config';
import SidebarConfig from '@/configs/sidebar.config';
import { PermissionNameEnum } from '@/enums/permission_name.enum';
import RoleHelper from '@/helpers/role.helper';
import IndexPage from '@/pages/index.page';

const {
    useDashboardWorkplacesMock,
    useDashboardKpisMock,
    useCapacityTableMock,
    useDemandHeatmapMock,
    useShowUpRateMock,
    useClearDashboardCacheMock,
} = vi.hoisted(() => ({
    useDashboardWorkplacesMock: vi.fn(),
    useDashboardKpisMock: vi.fn(),
    useCapacityTableMock: vi.fn(),
    useDemandHeatmapMock: vi.fn(),
    useShowUpRateMock: vi.fn(),
    useClearDashboardCacheMock: vi.fn((..._args: unknown[]) => ({ mutate: vi.fn(), isPending: false })),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) => {
            if (!params) return key;
            return Object.entries(params).reduce(
                (acc, [name, value]) => acc.replaceAll(`{{${name}}}`, String(value)),
                key,
            );
        },
    }),
}));

vi.mock('@/pages/admin/dashboard/hooks', () => ({
    useDashboardWorkplaces: (...args: unknown[]) => useDashboardWorkplacesMock(...args),
    useDashboardKpis: (...args: unknown[]) => useDashboardKpisMock(...args),
    useCapacityTable: (...args: unknown[]) => useCapacityTableMock(...args),
    useDemandHeatmap: (...args: unknown[]) => useDemandHeatmapMock(...args),
    useShowUpRate: (...args: unknown[]) => useShowUpRateMock(...args),
    useClearDashboardCache: (...args: unknown[]) => useClearDashboardCacheMock(...args),
}));

const successQuery = <T,>(data: T) => ({
    data: { data },
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
});

const loadingQuery = () => ({
    data: undefined,
    isLoading: true,
    isError: false,
    refetch: vi.fn(),
});

const workplaceList = [
    { id: 1, name: 'Yokohama A', is_test: false },
    { id: 2, name: 'Kawasaki Pack', is_test: false },
];

const setHappyPathDefaults = () => {
    useDashboardWorkplacesMock.mockReturnValue(
        successQuery({
            demand_workplaces: workplaceList,
            reliability_workplaces: workplaceList,
            demand_is_global: false,
            reliability_is_global: false,
        }),
    );

    useDashboardKpisMock.mockReturnValue(
        successQuery({
            active_pool: { value: 120, delta: 5, series: [10, 12, 11] },
            show_up_rate: { value: 92.5, delta: 1.2, series: [91, 92, 93] },
            avg_daily_check_ins: { value: 18, delta: 0, series: [16, 18, 20] },
            no_show_risk: { value: 4.5, delta: -0.8, series: [5, 4, 3] },
            daily_check_ins: {
                workplaces: workplaceList.map((wp) => ({ ...wp, is_other: false })),
                rows: [],
            },
            reliability_available: true,
        }),
    );

    useCapacityTableMock.mockReturnValue(
        successQuery({
            rows: [],
            summary: { critical_count: 0, tight_count: 0, worst: null },
        }),
    );

    useDemandHeatmapMock.mockReturnValue(
        successQuery({
            mode: 'groups',
            slots: [],
            cells: {},
        }),
    );

    useShowUpRateMock.mockReturnValue(
        successQuery({
            by_workplace: [],
            by_workplace_slot: [],
            by_day_of_week: Array.from({ length: 7 }, (_, i) => ({
                day_of_week: i,
                assigned_count: 0,
                check_in_count: 0,
                show_up_rate: null,
                low_sample: true,
            })),
            by_shift_time_group: ['Early Morning', 'Morning', 'Midday', 'Afternoon', 'Evening', 'Night'].map(
                (group) => ({
                    group,
                    assigned_count: 0,
                    check_in_count: 0,
                    show_up_rate: null,
                    low_sample: true,
                }),
            ),
        }),
    );
};

const renderPage = () =>
    render(
        <MantineProvider>
            <DatesProvider settings={{}}>
                <IndexPage />
            </DatesProvider>
        </MantineProvider>,
    );

describe('Admin Dashboard page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders KPI skeletons before queries resolve (Task 16.1)', () => {
        useDashboardWorkplacesMock.mockReturnValue(
            successQuery({
                demand_workplaces: workplaceList,
                reliability_workplaces: workplaceList,
                demand_is_global: false,
                reliability_is_global: false,
            }),
        );
        useDashboardKpisMock.mockReturnValue(loadingQuery());
        useCapacityTableMock.mockReturnValue(loadingQuery());
        useDemandHeatmapMock.mockReturnValue(loadingQuery());
        useShowUpRateMock.mockReturnValue(loadingQuery());

        const { container } = renderPage();

        // KPI card titles are rendered (the skeleton sits inside the card shell).
        expect(screen.getByText('Active worker pool')).toBeInTheDocument();
        expect(screen.getByText('Average show-up rate')).toBeInTheDocument();
        expect(screen.getByText('Average daily check-ins')).toBeInTheDocument();
        expect(screen.getByText('No-show risk (30d)')).toBeInTheDocument();

        // While loading, no concrete KPI value is shown.
        expect(container.querySelectorAll('.mantine-Skeleton-root').length).toBeGreaterThan(0);
    });

    it('renders the empty state when demand_workplaces is empty (Task 16.2)', () => {
        useDashboardWorkplacesMock.mockReturnValue(
            successQuery({
                demand_workplaces: [],
                reliability_workplaces: [],
                demand_is_global: false,
                reliability_is_global: false,
            }),
        );
        useDashboardKpisMock.mockReturnValue(successQuery(null));
        useCapacityTableMock.mockReturnValue(successQuery({ rows: [], summary: {} }));
        useDemandHeatmapMock.mockReturnValue(successQuery({ mode: 'groups', slots: [], cells: {} }));
        useShowUpRateMock.mockReturnValue(
            successQuery({ by_workplace: [], by_workplace_slot: [], by_day_of_week: [], by_shift_time_group: [] }),
        );

        renderPage();

        expect(screen.getByText('No accessible workplaces')).toBeInTheDocument();
        expect(
            screen.getByText('Ask your administrator to grant shift.list on at least one workplace.'),
        ).toBeInTheDocument();
        // Widget-row titles should NOT be rendered in empty state.
        expect(screen.queryByText('Capacity — next 14 days')).not.toBeInTheDocument();
        expect(screen.queryByText('Show-up rate')).not.toBeInTheDocument();
    });

    it('refetches KPI, demand heatmap, and show-up rate (but not capacity) when the window changes (Task 16.4)', () => {
        setHappyPathDefaults();

        renderPage();

        // Initial selection: preset 90 days.
        const initialSelection = { kind: 'preset', days: 90 };
        expect(useDashboardKpisMock).toHaveBeenCalledWith(
            expect.any(Array),
            expect.objectContaining(initialSelection),
            expect.any(Boolean),
        );
        expect(useDemandHeatmapMock).toHaveBeenCalledWith(
            expect.any(Array),
            expect.objectContaining(initialSelection),
            expect.any(String),
            expect.any(Boolean),
        );
        expect(useShowUpRateMock).toHaveBeenCalledWith(
            expect.any(Array),
            expect.objectContaining(initialSelection),
            expect.any(Boolean),
        );
        // Capacity hook does NOT take a window — keep that invariant.
        expect(useCapacityTableMock).toHaveBeenCalledWith(expect.any(Array), expect.any(Boolean));

        const initialKpiCalls = useDashboardKpisMock.mock.calls.length;
        const initialCapacityCalls = useCapacityTableMock.mock.calls.length;
        const initialHeatmapCalls = useDemandHeatmapMock.mock.calls.length;
        const initialShowUpCalls = useShowUpRateMock.mock.calls.length;

        // The window control is a Mantine SegmentedControl with a "30 days" option.
        const thirtyDayOption = screen.getByText('30 days');
        fireEvent.click(thirtyDayOption);

        // KPI, heatmap, and show-up rate hooks were called again with the 30-day preset.
        const lastKpi = useDashboardKpisMock.mock.calls.at(-1);
        const lastHeatmap = useDemandHeatmapMock.mock.calls.at(-1);
        const lastShowUp = useShowUpRateMock.mock.calls.at(-1);
        expect(lastKpi?.[1]).toEqual({ kind: 'preset', days: 30 });
        expect(lastHeatmap?.[1]).toEqual({ kind: 'preset', days: 30 });
        expect(lastShowUp?.[1]).toEqual({ kind: 'preset', days: 30 });
        expect(useDashboardKpisMock.mock.calls.length).toBeGreaterThan(initialKpiCalls);
        expect(useDemandHeatmapMock.mock.calls.length).toBeGreaterThan(initialHeatmapCalls);
        expect(useShowUpRateMock.mock.calls.length).toBeGreaterThan(initialShowUpCalls);

        // Capacity hook arity is unchanged — no `window` parameter was ever passed.
        const allCapacityCalls = useCapacityTableMock.mock.calls;
        allCapacityCalls.forEach((call) => {
            // Capacity takes (workplaceIds, enabled) — two args, never a window.
            expect(call.length).toBeLessThanOrEqual(2);
        });
        // Re-render may still occur due to react state propagation, but if it does we expect the
        // workplace-ids array (arg 0) to be the same reference shape, not a new window number.
        allCapacityCalls.slice(initialCapacityCalls).forEach((call) => {
            expect(Array.isArray(call[0])).toBe(true);
        });
    });

    it('switches to a custom date range when the Custom segment is selected', () => {
        setHappyPathDefaults();
        renderPage();

        fireEvent.click(screen.getByText('Custom'));

        const lastKpi = useDashboardKpisMock.mock.calls.at(-1);
        expect(lastKpi?.[1]).toMatchObject({ kind: 'custom' });
        expect(typeof (lastKpi?.[1] as { startDate?: string }).startDate).toBe('string');
        expect(typeof (lastKpi?.[1] as { endDate?: string }).endDate).toBe('string');
    });
});

describe('Sidebar dashboard entry permissions (Task 16.3)', () => {
    const findHomeEntry = () =>
        SidebarConfig.find((item) => 'route' in item && item.route === RouteConfig.HOME) as
            | { route: { permissions?: string[] } }
            | undefined;

    it('configures HOME with the shift.list permission', () => {
        expect(RouteConfig.HOME.permissions).toEqual([PermissionNameEnum.SHIFT_LIST.value]);
    });

    it('hides the sidebar entry for a user without shift.list', () => {
        const homeEntry = findHomeEntry();
        expect(homeEntry).toBeTruthy();
        const permissions = homeEntry!.route.permissions ?? [];
        const userPermissions: string[] = ['role.list', 'work_history.list']; // no shift.list
        expect(RoleHelper.hasAnyPermission(userPermissions, permissions)).toBe(false);
    });

    it('shows the sidebar entry for a user with shift.list', () => {
        const homeEntry = findHomeEntry();
        const permissions = homeEntry!.route.permissions ?? [];
        const userPermissions: string[] = [PermissionNameEnum.SHIFT_LIST.value];
        expect(RoleHelper.hasAnyPermission(userPermissions, permissions)).toBe(true);
    });
});
