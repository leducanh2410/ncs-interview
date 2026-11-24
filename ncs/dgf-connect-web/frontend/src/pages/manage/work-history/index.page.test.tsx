import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/manage/work-history/index.page';

const {
    commonSearchBoxSpy,
    dataTableSpy,
    useGetWorkHistoryQueryMock,
    usePermissionsMock,
    routerMock,
    requestDetailReportExportMock,
    useAtomMock,
} = vi.hoisted(() => ({
    commonSearchBoxSpy: vi.fn(),
    dataTableSpy: vi.fn(),
    useGetWorkHistoryQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    requestDetailReportExportMock: vi.fn(),
    useAtomMock: vi.fn(),
    routerMock: { query: {}, push: vi.fn() },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) =>
            params ? key.replace(/{{(\w+)}}/g, (_match, name) => String(params[name])) : key,
    }),
}));

vi.mock('react-icons/lu', () => ({
    LuChevronRight: () => null,
    LuFileSpreadsheet: () => null,
    LuPencilLine: () => null,
    LuPlus: () => null,
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { onClick, disabled, 'aria-label': ariaLabel }, children),
    Anchor: ({ children, href, onClick, component: _c }: any) => React.createElement('a', { href, onClick }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'status-badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, disabled, loading, onClick }: any) =>
        React.createElement('button', { disabled, onClick, 'aria-busy': loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
    UnstyledButton: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
    Tooltip: ({ children, label }: any) =>
        React.createElement('span', { 'data-testid': 'tooltip', 'data-label': label }, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: () => [false, { open: vi.fn(), close: vi.fn() }],
}));

vi.mock('jotai', () => ({
    useAtom: (...args: unknown[]) => useAtomMock(...args),
    atom: (v: any) => v,
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: null,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/work-history/api', () => ({
    useGetWorkHistoryQuery: (...args: unknown[]) => useGetWorkHistoryQueryMock(...args),
    requestDetailReportExport: (...args: unknown[]) => requestDetailReportExportMock(...args),
}));

vi.mock('@/pages/manage/work-history/partials/check_in_add_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/work-history/partials/check_in_update_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/manage/work-history/partials/approve_edit_request_modal', () => ({
    default: ({ checkIn, onClose }: any) =>
        checkIn
            ? React.createElement(
                  'div',
                  { 'data-testid': 'approve-modal' },
                  React.createElement('span', null, `approving:${checkIn.id}`),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'approve-modal-close' }, 'Close'),
              )
            : null,
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/edit_request_items', () => ({
    fieldLabel: (_t: any, field: string) => field,
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/request_detail_modal', () => ({
    default: ({ request, onClose, canProcess }: any) =>
        request
            ? React.createElement(
                  'div',
                  { 'data-testid': 'request-detail-modal' },
                  React.createElement('span', null, `status:${request.status}`),
                  React.createElement('span', null, `canProcess:${canProcess}`),
                  React.createElement(
                      'button',
                      { 'onClick': onClose, 'data-testid': 'request-detail-modal-close' },
                      'Close',
                  ),
              )
            : null,
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_e: any, value: string) => value,
        getColor: () => 'gray',
    },
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) =>
                React.createElement('span', { 'key': el.id, 'data-testid': `filter-${el.id}` }, el.label),
            ),
        );
    },
    userRenderItem: vi.fn(),
}));

vi.mock('@/components/DDataTable', () => ({
    headerWrapClassName: 'header-wrap',
    default: (props: any) => {
        dataTableSpy(props);
        const records: any[] = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        props.columns.map((col: any) =>
                            React.createElement(
                                'td',
                                { key: col.key },
                                col.render ? col.render(record) : record[col.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', { 'data-testid': 'user-avatar' }, user?.name),
}));

vi.mock('@/helpers/date.helper', () => {
    const chain = { subtract: () => chain, toDate: () => new Date() };
    return {
        default: {
            now: () => chain,
            parseAndFormat: (v: string) => v ?? '',
            formatWorkHour: () => ({ startTime: '09:00', endTime: '18:00' }),
            formatMinuteToHour: (v: number) => `${v}min`,
        },
        DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
    };
});

vi.mock('@/helpers/workplace.helper', () => ({
    default: { filterWorkplacesByAccess: vi.fn(() => [{ id: 1, name: 'Workplace A' }]) },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: (_route: string, params: any) => `/path/${params?.id ?? ''}` },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: vi.fn(), showSuccess: vi.fn() },
}));

vi.mock('@/configs/api.config', () => ({
    ApiConfig: { ADMIN_MASTER_USER_SEARCH: '/api/users/search' },
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        MANAGE_WORK_HISTORY_MONTHLY: { path: '/manage/work-history/monthly' },
    },
}));

const makeCheckIn = (overrides: Record<string, any> = {}) => ({
    id: 1,
    shift_id: 10,
    shift_user_id: 5,
    user_id: 3,
    check_in_time: '2026-05-10 09:00:00',
    check_out_time: '2026-05-10 18:00:00',
    checked_in_by: null,
    checked_out_by: null,
    work_hours: '8.00',
    work_minutes: 480,
    break_minutes: null,
    shift: {
        id: 10,
        date: '2026-05-10',
        break_minutes: null,
        workplace: { id: 1, name: 'Test Workplace' },
        workplace_shift: { start_time: '09:00:00', end_time: '18:00:00', break_duration: 30 },
    },
    user: { id: 3, name: 'Test User', staff_no: 'EMP001' },
    workplace: { id: 1, name: 'Test Workplace' },
    ...overrides,
});

const makePaginated = (items: any[]) => ({
    data: { data: items, current_page: 1, last_page: 1, per_page: 15, total: items.length },
});

describe('Work history index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORK_HISTORY_LIST: true,
            WORK_HISTORY_EXPORT: true,
            WORK_HISTORY_EDIT: true,
            WORK_HISTORY_ADD: true,
            WORK_HISTORY_EDIT_REQUEST_PROCESS: true,
        });

        useGetWorkHistoryQueryMock.mockReturnValue({
            data: makePaginated([makeCheckIn()]),
            isLoading: false,
        });

        useAtomMock.mockReturnValue([{ workplaces: [], workplace_access: [] }, vi.fn()]);
    });

    describe('search form', () => {
        it('has date-range, workplace, and user filters', () => {
            render(<IndexPage />);

            expect(screen.getByTestId('filter-dates')).toBeInTheDocument();
            expect(screen.getByTestId('filter-workplace_id')).toBeInTheDocument();
            expect(screen.getByTestId('filter-user_id')).toBeInTheDocument();

            expect(commonSearchBoxSpy).toHaveBeenCalledWith(
                expect.objectContaining({
                    elements: expect.arrayContaining([
                        expect.objectContaining({ id: 'dates', type: 'date-range' }),
                        expect.objectContaining({ id: 'workplace_id', type: 'select' }),
                        expect.objectContaining({ id: 'user_id', type: 'select-remote' }),
                        expect.objectContaining({ id: 'attendance_status', type: 'select' }),
                    ]),
                }),
            );
        });
    });

    describe('attendance status filter', () => {
        it('offers late, early leave and absent options with an all option', () => {
            render(<IndexPage />);

            const element = commonSearchBoxSpy.mock.calls[0][0].elements.find(
                (el: any) => el.id === 'attendance_status',
            );
            expect(element.options.map((option: any) => option.value)).toEqual(['late', 'early_leave', 'absent']);
            expect(element.showAll).toBe(true);
            expect(element.allValue).toBe('');
        });
    });

    describe('late and early leave', () => {
        it('shows late and early badges with minutes in the status column', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ late_minutes: 15, early_minutes: 30 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('Late 15 min')).toBeInTheDocument();
            expect(screen.getByText('Early 30 min')).toBeInTheDocument();
        });

        it('shows the submitted late and early reasons in the reason column', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        late_minutes: 15,
                        early_minutes: 30,
                        late_reason: 'Train delay',
                        early_reason: 'Sick',
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('Late arrival: Train delay')).toBeInTheDocument();
            expect(screen.getByText('Early leave: Sick')).toBeInTheDocument();
        });

        it('hides reasons whose deviation no longer applies after a correction', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        late_minutes: 0,
                        early_minutes: 0,
                        late_reason: 'Train delay',
                        early_reason: 'Sick',
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.queryByText('Late arrival: Train delay')).not.toBeInTheDocument();
            expect(screen.queryByText('Early leave: Sick')).not.toBeInTheDocument();
        });

        it('shows no deviation badge for an on-time check-in', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ late_minutes: 0, early_minutes: 0 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.queryByText(/Late \d+ min/)).not.toBeInTheDocument();
            expect(screen.queryByText(/Early \d+ min/)).not.toBeInTheDocument();
        });
    });

    describe('break time column', () => {
        it('shows CheckIn.break_minutes when set', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ break_minutes: 45 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('45')).toBeInTheDocument();
        });

        it('falls back to shift.break_minutes when CheckIn.break_minutes is null', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        break_minutes: null,
                        shift: {
                            id: 10,
                            date: '2026-05-10',
                            break_minutes: 60,
                            workplace: { id: 1, name: 'Workplace' },
                            workplace_shift: { start_time: '09:00:00', end_time: '18:00:00', break_duration: 30 },
                        },
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('60')).toBeInTheDocument();
        });

        it('falls back to workplace_shift.break_duration when both break_minutes are null', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        break_minutes: null,
                        shift: {
                            id: 10,
                            date: '2026-05-10',
                            break_minutes: null,
                            workplace: { id: 1, name: 'Workplace' },
                            workplace_shift: { start_time: '09:00:00', end_time: '18:00:00', break_duration: 30 },
                        },
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('30')).toBeInTheDocument();
        });
    });

    describe('start/end time column', () => {
        it('shows (Admin) suffix when checked_in_by is a truthy id', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ checked_in_by: 99 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText(/\(Admin\)/)).toBeInTheDocument();
        });

        it('shows (System) suffix when checked_in_by is 0', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ checked_in_by: 0 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText(/\(System\)/)).toBeInTheDocument();
        });

        it('shows (Admin) suffix when checked_out_by is a truthy id', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ checked_out_by: 99 })]),
                isLoading: false,
            });

            render(<IndexPage />);

            const cells = screen.getAllByText(/\(Admin\)/);
            expect(cells.length).toBeGreaterThan(0);
        });
    });

    describe('permission-based UI', () => {
        it('disables Add user button without WORK_HISTORY_ADD permission', () => {
            usePermissionsMock.mockReturnValue({
                WORK_HISTORY_LIST: true,
                WORK_HISTORY_EXPORT: true,
                WORK_HISTORY_EDIT: true,
                WORK_HISTORY_ADD: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('Add user to shift').closest('button')).toBeDisabled();
        });

        it('disables Export button without WORK_HISTORY_EXPORT permission', () => {
            usePermissionsMock.mockReturnValue({
                WORK_HISTORY_LIST: true,
                WORK_HISTORY_EXPORT: false,
                WORK_HISTORY_EDIT: true,
                WORK_HISTORY_ADD: true,
            });

            render(<IndexPage />);

            expect(screen.getByText('Export excel').closest('button')).toBeDisabled();
        });

        it('requests a background export and navigates to the batch detail page', async () => {
            requestDetailReportExportMock.mockResolvedValue({ data: { id: 42 } });

            render(<IndexPage />);

            fireEvent.click(screen.getByText('Export excel'));

            await waitFor(() => {
                expect(requestDetailReportExportMock).toHaveBeenCalledWith(routerMock.query);
                expect(routerMock.push).toHaveBeenCalledWith('/path/42');
            });
        });

        it('disables Export button when there is no data even with permission', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByText('Export excel').closest('button')).toBeDisabled();
        });

        it('passes noPermission=true to table when user lacks WORK_HISTORY_LIST', () => {
            usePermissionsMock.mockReturnValue({
                WORK_HISTORY_LIST: false,
                WORK_HISTORY_EXPORT: false,
                WORK_HISTORY_EDIT: false,
                WORK_HISTORY_ADD: false,
                WORK_HISTORY_EDIT_REQUEST_PROCESS: false,
            });

            render(<IndexPage />);

            expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
        });
    });

    describe('edit request status/reason columns', () => {
        it('shows "-" for status and reason when there is no edit request', () => {
            render(<IndexPage />);

            expect(screen.queryByTestId('status-badge')).not.toBeInTheDocument();
            expect(screen.queryByTestId('tooltip')).not.toBeInTheDocument();
        });

        it('renders the latest edit request status badge and per-field reasons', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        latest_edit_request: {
                            id: 1,
                            status: 'pending',
                            items: [{ id: 1, field: 'check_in_time', reason: 'Máy chấm công lỗi' }],
                        },
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByTestId('status-badge')).toHaveTextContent('pending');
            expect(screen.getByTestId('tooltip')).toHaveAttribute(
                'data-label',
                expect.stringContaining('Máy chấm công lỗi'),
            );
        });

        it('opens the read-only detail modal when the edit reason is clicked', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        latest_edit_request: {
                            id: 1,
                            status: 'rejected',
                            reject_reason: 'Không khớp camera',
                            items: [{ id: 1, field: 'check_in_time', reason: 'Máy chấm công lỗi' }],
                        },
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.queryByTestId('request-detail-modal')).not.toBeInTheDocument();
            fireEvent.click(screen.getByText('check_in_time: Máy chấm công lỗi'));

            const modal = screen.getByTestId('request-detail-modal');
            expect(modal).toHaveTextContent('status:rejected');
            expect(modal).toHaveTextContent('canProcess:false');

            fireEvent.click(screen.getByTestId('request-detail-modal-close'));
            expect(screen.queryByTestId('request-detail-modal')).not.toBeInTheDocument();
        });

        it('includes the rejection reason alongside the submitter reasons when the latest request was rejected', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([
                    makeCheckIn({
                        latest_edit_request: {
                            id: 1,
                            status: 'rejected',
                            reject_reason: 'Không khớp camera',
                            items: [{ id: 1, field: 'check_in_time', reason: 'Máy chấm công lỗi' }],
                        },
                    }),
                ]),
                isLoading: false,
            });

            render(<IndexPage />);

            const label = screen.getByTestId('tooltip').getAttribute('data-label');
            expect(label).toContain('Máy chấm công lỗi');
            expect(label).toContain('Không khớp camera');
        });
    });

    describe('edit action while a request is pending', () => {
        it('disables the pencil edit action while the latest request is pending', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ latest_edit_request: { id: 1, status: 'pending', items: [] } })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
        });
    });

    describe('inline approve action', () => {
        it('is not shown without WORK_HISTORY_EDIT_REQUEST_PROCESS permission', () => {
            usePermissionsMock.mockReturnValue({
                WORK_HISTORY_LIST: true,
                WORK_HISTORY_EXPORT: true,
                WORK_HISTORY_EDIT: true,
                WORK_HISTORY_ADD: true,
                WORK_HISTORY_EDIT_REQUEST_PROCESS: false,
            });
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ latest_edit_request: { id: 1, status: 'pending', items: [] } })]),
                isLoading: false,
            });

            render(<IndexPage />);

            expect(screen.queryByText('Approve')).not.toBeInTheDocument();
        });

        it('is not shown when there is no pending request even with permission', () => {
            render(<IndexPage />);

            expect(screen.queryByText('Approve')).not.toBeInTheDocument();
        });

        it('opens the approve confirmation modal for the row, showing old->new values before applying', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ latest_edit_request: { id: 1, status: 'pending', items: [] } })]),
                isLoading: false,
            });

            render(<IndexPage />);

            fireEvent.click(screen.getByText('Approve'));

            expect(screen.getByTestId('approve-modal')).toHaveTextContent('approving:1');
        });

        it('closes the approve modal via its onClose callback', () => {
            useGetWorkHistoryQueryMock.mockReturnValue({
                data: makePaginated([makeCheckIn({ latest_edit_request: { id: 1, status: 'pending', items: [] } })]),
                isLoading: false,
            });

            render(<IndexPage />);

            fireEvent.click(screen.getByText('Approve'));
            expect(screen.getByTestId('approve-modal')).toBeInTheDocument();

            fireEvent.click(screen.getByTestId('approve-modal-close'));
            expect(screen.queryByTestId('approve-modal')).not.toBeInTheDocument();
        });
    });
});
