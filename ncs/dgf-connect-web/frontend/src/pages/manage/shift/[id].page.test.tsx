import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/shift/[id].page';

const { useShiftDetailMock, routerQueryBox, routerPushMock, removeQueriesMock, weeklyWorkLoadsBox, showErrorMock } =
    vi.hoisted(() => ({
        weeklyWorkLoadsBox: { current: undefined as Record<string, unknown> | undefined },
        showErrorMock: vi.fn(),
        useShiftDetailMock: vi.fn(),
        routerQueryBox: { current: {} as Record<string, unknown> },
        routerPushMock: vi.fn(),
        removeQueriesMock: vi.fn(),
    }));

vi.mock('@tanstack/react-query', () => ({
    useQueryClient: () => ({ removeQueries: removeQueriesMock }),
    useQuery: () => ({ data: weeklyWorkLoadsBox.current }),
    keepPreviousData: (previousData: unknown) => previousData,
}));

vi.mock('@/pages/manage/shift/partials/update/useShiftDetail', () => ({
    useShiftDetail: () => useShiftDetailMock(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: routerQueryBox.current, push: routerPushMock }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
    },
}));

vi.mock('@/helpers/shift.helper', () => ({
    default: {
        getStatus: () => 'active',
        isShiftEnded: () => false,
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: vi.fn(),
        showError: showErrorMock,
        showInfo: vi.fn(),
    },
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        MANAGE_SHIFT_LIST: { path: '/manage/shift' },
    },
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Switch: ({ label, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            typeof label === 'string' ? label : null,
            React.createElement('input', {
                type: 'checkbox',
                checked,
                onChange: (e: React.ChangeEvent<HTMLInputElement>) => onChange?.(e),
            }),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

// Only the rendered component is mocked (DDataTable and its many dependencies aren't worth
// wiring up here). resolveInitialAttendanceFilter comes from the real, dependency-free
// attendance_filter module — not reimplemented — so a wiring bug in the page (e.g. swapped
// attendance/attendanceGroup arguments, or the wrong groupId/scope) fails these tests
// instead of only ever passing because the mock quietly encodes the same assumption as the
// production code. (Importing assignment_table's own module here instead would drag in
// FormHelper/DateHelper's real i18n singleton, which conflicts with this file's partial
// 'react-i18next' mock.)
vi.mock('@/pages/manage/shift/partials/assignment_table', async () => {
    const actual = await vi.importActual<typeof import('@/pages/manage/shift/partials/attendance_filter')>(
        '@/pages/manage/shift/partials/attendance_filter',
    );
    return {
        ...actual,
        default: ({
            groupId,
            title,
            initialAttendanceFilter,
            onMove,
        }: {
            groupId: string;
            title: string;
            initialAttendanceFilter?: string;
            onMove: (userIds: string[], targetGroup: string) => void;
        }) =>
            React.createElement(
                'div',
                {
                    'data-testid': `table-${groupId}`,
                    // String sentinel (not the prop's raw undefined) so tests can assert
                    // "no seed for this table" via a plain attribute query.
                    'data-initial-attendance-filter': initialAttendanceFilter ?? '(undefined)',
                },
                title,
                React.createElement(
                    'button',
                    { type: 'button', onClick: () => onMove(['7', '8'], '1') },
                    `assign-from-${groupId}-to-position`,
                ),
                React.createElement(
                    'button',
                    { type: 'button', onClick: () => onMove(['7', '8'], 'rejected') },
                    `assign-from-${groupId}-to-rejected`,
                ),
            ),
    };
});

vi.mock('@/pages/manage/shift/partials/update/action_bar', () => ({
    default: () => React.createElement('div', { 'data-testid': 'action-bar' }),
}));

vi.mock('@/pages/manage/shift/partials/update/shift_info_card', () => ({
    default: ({ onUpdateConfig }: { onUpdateConfig: () => void }) =>
        React.createElement(
            'div',
            { 'data-testid': 'shift-info-card' },
            React.createElement('button', { type: 'button', onClick: onUpdateConfig }, 'Edit config'),
        ),
}));

vi.mock('@/pages/manage/shift/partials/update/error_alerts', () => ({
    default: () => React.createElement('div', { 'data-testid': 'error-alerts' }),
}));

vi.mock('@/pages/manage/shift/partials/update/modal_import_csv', () => ({
    default: ({ isShow }: { isShow: boolean }) =>
        isShow ? React.createElement('div', { 'data-testid': 'import-modal' }) : null,
}));

vi.mock('@/pages/manage/shift/partials/update/add_user_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/shift/partials/update/bulk_assign_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/shift/partials/update/config_modal', () => ({
    default: ({ isOpen }: { isOpen: boolean }) =>
        isOpen ? React.createElement('div', { 'data-testid': 'config-modal' }) : null,
}));

vi.mock('@/pages/manage/shift/partials/update/overtime_modal', () => ({
    default: ({ isOpen }: { isOpen: boolean }) =>
        isOpen ? React.createElement('div', { 'data-testid': 'overtime-modal' }) : null,
}));

vi.mock('@/pages/manage/shift/partials/update/delete_shift_modal', () => ({
    default: ({ isOpen, shiftId, onDeleted }: { isOpen: boolean; shiftId: string; onDeleted: () => void }) =>
        isOpen
            ? React.createElement(
                  'div',
                  { 'data-testid': 'delete-modal', 'data-shift-id': shiftId },
                  React.createElement('button', { type: 'button', onClick: onDeleted }, 'Confirm delete'),
              )
            : null,
}));

// ── fixtures ─────────────────────────────────────────────────────────────────

const buildShift = (overrides: Record<string, unknown> = {}) => ({
    id: 42,
    date: '2026-06-10',
    workplace_id: 1,
    workplace_shift_id: 10,
    salary_per_hour: null,
    fixed_total: 5,
    temp_total: 3,
    max_registered_temp: null,
    break_minutes: null,
    workplace: { id: 1, name: 'Tokyo HQ' },
    workplace_shift: {
        id: 10,
        start_time: '09:00:00',
        end_time: '18:00:00',
        leaders: [],
    },
    ...overrides,
});

const buildHookReturn = (overrides: Record<string, unknown> = {}) => ({
    id: '42',
    perms: { SHIFT_LIST: true, SHIFT_EDIT: true, SHIFT_EXPORT: true, SHIFT_IMPORT: true, SHIFT_DELETE: true },
    isLoading: false,
    data: { data: { shift: buildShift() } },
    shift: buildShift(),
    positions: [
        { id: 1, name: 'Staff', sort_order: null },
        { id: 2, name: 'Leader', sort_order: 1 },
    ],
    isReadOnly: false,
    hasChanges: false,
    selectionResetKey: 0,
    form: {
        errors: {},
        onSubmit: (fn: (v: unknown) => void) => () => fn({}),
    },
    users: {},
    userIsFixed: {},
    userHasAbsentNotice: {},
    userStatus: {},
    userPhoneNumbers: {},
    userShiftMeta: {},
    groupUsers: { 'pending': [], 'rejected': [], '1': [], '2': [] },
    positionErrors: {},
    csvImportErrorData: [],
    addToPositionId: null,
    setAddToPositionId: vi.fn(),
    isShowImportModal: false,
    setIsShowImportModal: vi.fn(),
    encoding: 'utf-8',
    setEncoding: vi.fn(),
    fileInputRef: { current: vi.fn() },
    importCsvMutation: { isPending: false },
    updateMutation: { isPending: false },
    moveUsers: vi.fn(),
    removeUsers: vi.fn(),
    updateUserShiftMeta: vi.fn(),
    handleAddUsers: vi.fn(),
    handleSave: vi.fn(),
    handleCancel: vi.fn(),
    handleCsvUpload: vi.fn(),
    handleExport: vi.fn(),
    ...overrides,
});

// ── tests ────────────────────────────────────────────────────────────────────

describe('Shift detail page ([id].page)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // localStorage.getItem must return null (not undefined) so the "no stored
        // preference" branch is taken and isSplitByPositionView stays at its default true.
        vi.mocked(window.localStorage.getItem).mockReturnValue(null);
        useShiftDetailMock.mockReturnValue(buildHookReturn());
        routerQueryBox.current = {};
        weeklyWorkLoadsBox.current = undefined;
    });

    describe('weekly work-hour limit', () => {
        const usersWithOneBlocked = () => {
            weeklyWorkLoadsBox.current = {
                '7': { is_blocked: true, is_shift_counted: false },
                '8': { is_blocked: false, is_shift_counted: false },
            };
            const moveUsers = vi.fn();
            useShiftDetailMock.mockReturnValue(
                buildHookReturn({
                    moveUsers,
                    users: { '7': { id: 7, name: 'Over Limit' }, '8': { id: 8, name: 'Within Limit' } },
                }),
            );
            return moveUsers;
        };

        it('assigns only users within the weekly limit and reports the blocked ones', async () => {
            const moveUsers = usersWithOneBlocked();
            const user = userEvent.setup();
            render(<IndexPage />);

            await user.click(screen.getByRole('button', { name: 'assign-from-pending-to-position' }));

            expect(moveUsers).toHaveBeenCalledWith(['8'], '1');
            expect(showErrorMock).toHaveBeenCalledWith(
                'Cannot assign Over Limit: the weekly work-hour limit would be exceeded',
            );
        });

        it('keeps approved users who went over the limit out of other positions but lets them reduce hours', async () => {
            weeklyWorkLoadsBox.current = {
                '7': { is_blocked: false, is_shift_counted: true, status: 'over' },
                '8': { is_blocked: false, is_shift_counted: true, status: 'near' },
            };
            const moveUsers = vi.fn();
            useShiftDetailMock.mockReturnValue(
                buildHookReturn({
                    moveUsers,
                    users: { '7': { id: 7, name: 'Over After Approval' }, '8': { id: 8, name: 'Within Limit' } },
                }),
            );
            const user = userEvent.setup();
            render(<IndexPage />);

            await user.click(screen.getByRole('button', { name: 'assign-from-pending-to-position' }));
            expect(moveUsers).toHaveBeenCalledWith(['8'], '1');
            expect(showErrorMock).toHaveBeenCalledWith(
                'Cannot move Over After Approval to another position: already over the weekly work-hour limit, move them to the register or rejected list instead',
            );

            await user.click(screen.getByRole('button', { name: 'assign-from-pending-to-rejected' }));
            expect(moveUsers).toHaveBeenCalledWith(['7', '8'], 'rejected');
        });

        it('still lets blocked users be moved back to the rejected list', async () => {
            const moveUsers = usersWithOneBlocked();
            const user = userEvent.setup();
            render(<IndexPage />);

            await user.click(screen.getByRole('button', { name: 'assign-from-pending-to-rejected' }));

            expect(moveUsers).toHaveBeenCalledWith(['7', '8'], 'rejected');
            expect(showErrorMock).not.toHaveBeenCalled();
        });
    });

    it('shows a loader while shift data is loading', () => {
        useShiftDetailMock.mockReturnValue(buildHookReturn({ isLoading: true, data: undefined, shift: undefined }));

        render(<IndexPage />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('renders nothing when the route has no shift id', () => {
        useShiftDetailMock.mockReturnValue(buildHookReturn({ id: undefined }));

        const { container } = render(<IndexPage />);

        expect(container.firstChild).toBeNull();
    });

    it('renders breadcrumbs linking to home and the shift list', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');
        expect(screen.getByRole('link', { name: 'Shift List' })).toHaveAttribute('href', '/manage/shift');
        expect(screen.getByText('Shift Detail')).toBeInTheDocument();
    });

    it('renders the page title as workplace name and formatted time range', () => {
        render(<IndexPage />);

        expect(screen.getByRole('heading')).toHaveTextContent('Tokyo HQ | 09:00 ~ 18:00');
    });

    it('shows the Import CSV button when SHIFT_IMPORT permission is granted', () => {
        render(<IndexPage />);

        expect(screen.getByRole('button', { name: 'Import CSV' })).toBeInTheDocument();
    });

    it('hides the Import CSV button when SHIFT_IMPORT permission is absent', () => {
        useShiftDetailMock.mockReturnValue(
            buildHookReturn({
                perms: { SHIFT_LIST: true, SHIFT_EDIT: true, SHIFT_EXPORT: true, SHIFT_IMPORT: false },
            }),
        );

        render(<IndexPage />);

        expect(screen.queryByRole('button', { name: 'Import CSV' })).not.toBeInTheDocument();
    });

    it('hides the Delete shift button when SHIFT_DELETE permission is absent', () => {
        useShiftDetailMock.mockReturnValue(
            buildHookReturn({
                perms: {
                    SHIFT_LIST: true,
                    SHIFT_EDIT: true,
                    SHIFT_EXPORT: true,
                    SHIFT_IMPORT: true,
                    SHIFT_DELETE: false,
                },
            }),
        );

        render(<IndexPage />);

        expect(screen.queryByRole('button', { name: 'Delete shift' })).not.toBeInTheDocument();
        expect(screen.queryByTestId('delete-modal')).not.toBeInTheDocument();
    });

    it('opens the delete modal for this shift and returns to the list once deleted', async () => {
        const user = userEvent.setup();
        let resolveNavigation: () => void = () => {};
        routerPushMock.mockReturnValue(new Promise<void>((resolve) => (resolveNavigation = resolve)));
        render(<IndexPage />);

        expect(screen.queryByTestId('delete-modal')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Delete shift' }));
        expect(screen.getByTestId('delete-modal')).toHaveAttribute('data-shift-id', '42');

        await user.click(screen.getByRole('button', { name: 'Confirm delete' }));

        expect(routerPushMock).toHaveBeenCalledWith('/manage/shift');
        expect(screen.queryByTestId('delete-modal')).not.toBeInTheDocument();
        // The deleted shift's cached queries are dropped only after the page has navigated away,
        // so nothing refetches (and 404s) while the detail page is still mounted.
        expect(removeQueriesMock).not.toHaveBeenCalled();
        resolveNavigation();
        await waitFor(() => expect(removeQueriesMock).toHaveBeenCalledWith({ queryKey: ['manage/shifts', '42'] }));
    });

    it('renders per-position tables in the default split-by-position view', () => {
        render(<IndexPage />);

        // One table per position (id 1 and 2)
        expect(screen.getByTestId('table-1')).toBeInTheDocument();
        expect(screen.getByTestId('table-2')).toBeInTheDocument();
        // The combined approved table must not be visible yet
        expect(screen.queryByTestId('table-approved')).not.toBeInTheDocument();
    });

    it('shows the imported headcount target next to the assigned count of a position', () => {
        useShiftDetailMock.mockReturnValue(
            buildHookReturn({
                data: {
                    data: {
                        shift: buildShift(),
                        position_totals: [
                            { id: 1, shift_id: 42, workplace_position_id: 1, fixed_total: 17, temp_total: 0 },
                        ],
                    },
                },
            }),
        );

        render(<IndexPage />);

        expect(screen.getByTestId('table-1')).toHaveTextContent('Staff (0/17)');
        expect(screen.getByTestId('table-2')).toHaveTextContent('Leader (0)');
    });

    it('renders a single approved table after toggling the split-by-position view off', async () => {
        const user = userEvent.setup();
        render(<IndexPage />);

        await user.click(screen.getByRole('checkbox'));

        expect(screen.queryByTestId('table-1')).not.toBeInTheDocument();
        expect(screen.getByTestId('table-approved')).toBeInTheDocument();
    });

    describe('attendance filter URL wiring', () => {
        it('seeds only the table whose attendanceScope matches ?attendanceGroup=, leaving the rest undefined', () => {
            routerQueryBox.current = { attendance: 'unconfirmed', attendanceGroup: 'pending' };
            render(<IndexPage />);

            expect(screen.getByTestId('table-pending')).toHaveAttribute(
                'data-initial-attendance-filter',
                'unconfirmed',
            );
            expect(screen.getByTestId('table-1')).toHaveAttribute('data-initial-attendance-filter', '(undefined)');
            expect(screen.getByTestId('table-2')).toHaveAttribute('data-initial-attendance-filter', '(undefined)');
            expect(screen.getByTestId('table-rejected')).toHaveAttribute(
                'data-initial-attendance-filter',
                '(undefined)',
            );
        });

        it('applies a bare ?attendance= with no ?attendanceGroup= to every table (legacy/external link contract)', () => {
            routerQueryBox.current = { attendance: 'not_checked_in' };
            render(<IndexPage />);

            for (const testId of ['table-pending', 'table-1', 'table-2', 'table-rejected']) {
                expect(screen.getByTestId(testId)).toHaveAttribute('data-initial-attendance-filter', 'not_checked_in');
            }
        });

        it("seeds every per-position table from the stable 'approved' scope regardless of the split-by-position view", () => {
            routerQueryBox.current = { attendance: 'unconfirmed', attendanceGroup: 'approved' };
            render(<IndexPage />);

            // Split-by-position view is the default: both position tables share the
            // 'approved' scope, not their own posId, so a link written from the combined
            // view still applies here.
            expect(screen.getByTestId('table-1')).toHaveAttribute('data-initial-attendance-filter', 'unconfirmed');
            expect(screen.getByTestId('table-2')).toHaveAttribute('data-initial-attendance-filter', 'unconfirmed');
            expect(screen.getByTestId('table-pending')).toHaveAttribute(
                'data-initial-attendance-filter',
                '(undefined)',
            );
        });
    });

    it('opens the config modal when ShiftInfoCard triggers onUpdateConfig', async () => {
        const user = userEvent.setup();
        render(<IndexPage />);

        expect(screen.queryByTestId('config-modal')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Edit config' }));
        expect(screen.getByTestId('config-modal')).toBeInTheDocument();
    });
});
