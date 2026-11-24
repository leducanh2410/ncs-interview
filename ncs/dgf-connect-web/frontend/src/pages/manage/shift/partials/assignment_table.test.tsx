import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UserWithAbsenceCount } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import AssignmentTable, { resolveInitialAttendanceFilter } from '@/pages/manage/shift/partials/assignment_table';
import { UserShiftTableMeta } from '@/pages/manage/shift/partials/update/useShiftDetail';
import { ShiftWeeklyWorkLoad } from '@/pages/manage/shift/api';

const {
    dataTableSpy,
    usePermissionsMock,
    useUpdateAllowOvertimeMutationMock,
    updateAllowOvertimeMutateAsyncMock,
    useUpdateSalaryMutationMock,
    updateSalaryMutateAsyncMock,
    showSuccessMock,
    showErrorMock,
    setFormErrorMock,
    checkInOutModalSpy,
    dateHelperParseMock,
    chatIntentBox,
    setChatIntentMock,
    authBox,
    useFindDirectConversationMutationMock,
    findDirectConversationMutateAsyncMock,
    routerMock,
    routerReplaceMock,
} = vi.hoisted(() => {
    const box: { current: unknown } = { current: null };
    return {
        dataTableSpy: vi.fn(),
        usePermissionsMock: vi.fn(),
        useUpdateAllowOvertimeMutationMock: vi.fn(),
        updateAllowOvertimeMutateAsyncMock: vi.fn(),
        useUpdateSalaryMutationMock: vi.fn(),
        updateSalaryMutateAsyncMock: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
        setFormErrorMock: vi.fn(),
        checkInOutModalSpy: vi.fn(),
        dateHelperParseMock: vi.fn(),
        chatIntentBox: box,
        setChatIntentMock: vi.fn((next: unknown) => {
            box.current = typeof next === 'function' ? (next as (prev: unknown) => unknown)(box.current) : next;
        }),
        // Default to a user id that never matches a test row (rows use 1/2/3), so the
        // self-message guard doesn't need to be considered by tests that aren't about it.
        authBox: { current: { user: { id: 999 } } } as { current: { user: { id: number } } },
        useFindDirectConversationMutationMock: vi.fn(),
        findDirectConversationMutateAsyncMock: vi.fn(),
        routerMock: { query: {} as Record<string, unknown>, pathname: '/manage/shift/[id]' },
        routerReplaceMock: vi.fn(),
    };
});

vi.mock('next/router', () => ({
    useRouter: () => ({ ...routerMock, replace: routerReplaceMock }),
}));

const SALARY_FORM_VALUES = { salary_per_hour: 1500 };

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/form', () => ({
    useForm: () => ({
        onSubmit: (handler: any) => (e: any) => {
            e?.preventDefault?.();
            return handler(SALARY_FORM_VALUES);
        },
        getInputProps: () => ({ value: SALARY_FORM_VALUES.salary_per_hour, onChange: vi.fn() }),
        setValues: vi.fn(),
        resetDirty: vi.fn(),
    }),
}));

vi.mock('@mantine/hooks', () => ({
    useDebouncedValue: (value: string) => [value],
}));

vi.mock('jotai', () => ({
    useAtom: (a: unknown) => (a === 'CHAT_INTENT_ATOM' ? [chatIntentBox.current, setChatIntentMock] : [null, vi.fn()]),
    useAtomValue: (a: unknown) => (a === 'AUTH_ATOM' ? authBox.current : null),
}));

vi.mock('@/pages/global.state', () => ({
    authAtom: 'AUTH_ATOM',
    chatIntentAtom: 'CHAT_INTENT_ATOM',
}));

vi.mock('@/components/chat/api', () => ({
    useFindDirectConversationMutation: (...args: unknown[]) => useFindDirectConversationMutationMock(...args),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, title, loading }: any) =>
        React.createElement(
            'button',
            { 'type': 'button', onClick, 'disabled': disabled || loading, 'aria-label': title ?? 'action-icon' },
            children,
        ),
    SegmentedControl: ({ value, onChange, data }: any) =>
        React.createElement(
            'div',
            { 'role': 'group', 'aria-label': 'attendance-filter' },
            data.map((opt: any) =>
                React.createElement(
                    'button',
                    {
                        'key': opt.value,
                        'type': 'button',
                        'aria-pressed': value === opt.value,
                        'onClick': () => onChange(opt.value),
                    },
                    opt.label,
                ),
            ),
        ),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, disabled, loading, type }: any) =>
        React.createElement('button', { type: type ?? 'button', onClick, disabled: disabled || loading }, children),
    Card: ({ children }: any) => React.createElement('div', null, children),
    CardSection: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Input: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        ClearButton: ({ onClick }: any) =>
            React.createElement('button', { 'type': 'button', 'aria-label': 'clear-search', onClick }),
    }),
    Menu: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        Target: ({ children }: any) => children,
        Dropdown: ({ children }: any) => React.createElement('div', null, children),
        Item: ({ children, onClick, disabled }: any) =>
            React.createElement('button', { type: 'button', role: 'menuitem', onClick, disabled }, children),
        Label: ({ children }: any) => React.createElement('div', null, children),
    }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    NumberInput: ({ label, value }: any) =>
        React.createElement('label', null, label, React.createElement('input', { readOnly: true, value })),
    Switch: ({ label, checked, onChange, disabled }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { type: 'checkbox', role: 'switch', checked, disabled, onChange }),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ value, onChange, placeholder, rightSection }: any) =>
        React.createElement(
            'div',
            null,
            React.createElement('input', { 'type': 'text', 'aria-label': placeholder, value, onChange }),
            rightSection,
        ),
    Tooltip: ({ children, label, disabled }: any) =>
        disabled ? children : React.createElement('div', { title: label }, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useUpdateAllowOvertimeMutation: (...args: unknown[]) => useUpdateAllowOvertimeMutationMock(...args),
    useUpdateSalaryMutation: (...args: unknown[]) => useUpdateSalaryMutationMock(...args),
}));

vi.mock('@/helpers/date.helper', async () => {
    const actual = await vi.importActual<typeof import('@/helpers/date.helper')>('@/helpers/date.helper');
    return {
        ...actual,
        default: {
            ...actual.default,
            parse: dateHelperParseMock,
            parseAndFormat: (value: string | null) => (value ? `FORMATTED(${value})` : ''),
        },
    };
});

vi.mock('@/helpers/form.helper', () => ({
    default: { setFormError: setFormErrorMock },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: showSuccessMock, showError: showErrorMock },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user.name),
}));

vi.mock('@/pages/manage/shift/partials/weekly_work_load', () => ({
    WeeklyWorkLoadBar: ({ workLoad }: any) =>
        React.createElement(
            'span',
            { 'data-testid': 'weekly-work-load' },
            workLoad ? `${workLoad.projected_hours}/${workLoad.max_hour_per_week}` : 'no-work-load',
        ),
    isAssignmentBlocked: (workLoad: any) => Boolean(workLoad?.is_blocked),
    isOverLimitAfterApproval: (workLoad: any) => Boolean(workLoad?.is_shift_counted) && workLoad?.status === 'over',
    isPositionTarget: (groupId: string) => groupId !== 'pending' && groupId !== 'rejected',
}));

vi.mock('@/pages/manage/shift/partials/absence_warning', () => ({
    default: ({ absenceCount }: { absenceCount: number }) =>
        absenceCount >= 1 ? React.createElement('span', { title: 'absence-warning' }, absenceCount) : null,
}));

vi.mock('@/pages/manage/shift/partials/shift_check_in_out_modal', () => ({
    default: (props: any) => {
        checkInOutModalSpy(props);
        return props.shiftUserId !== null
            ? React.createElement(
                  'div',
                  { 'data-testid': 'check-in-out-modal' },
                  props.isCheckIn ? 'check-in' : 'check-out',
              )
            : null;
    },
}));

vi.mock('@/components/DDataTable', () => ({
    headerWrapClassName: 'header-wrap',
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            null,
            React.createElement(
                'thead',
                null,
                React.createElement(
                    'tr',
                    null,
                    props.columns.map((col: any) => React.createElement('th', { key: col.key }, col.name)),
                ),
            ),
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { 'key': record.id, 'data-row-id': record.id },
                        props.selectedRecords !== undefined &&
                            React.createElement('td', null, [
                                React.createElement('input', {
                                    'key': 'cb',
                                    'type': 'checkbox',
                                    'aria-label': `select-${record.id}`,
                                    'checked': props.selectedRecords.some((r: any) => r.id === record.id),
                                    'onChange': (e: React.ChangeEvent<HTMLInputElement>) => {
                                        const next = e.target.checked
                                            ? [...props.selectedRecords, record]
                                            : props.selectedRecords.filter((r: any) => r.id !== record.id);
                                        props.onSelectedRecordsChange(next);
                                    },
                                }),
                            ]),
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

const buildUser = (overrides: Partial<UserWithAbsenceCount> = {}): UserWithAbsenceCount =>
    ({
        id: 1,
        name: 'Yamada Taro',
        email: 'yamada@example.com',
        staff_no: '001',
        absence_count: 0,
        ...overrides,
    }) as UserWithAbsenceCount;

const positions: WorkplacePosition[] = [
    { id: 1, name: 'Staff' } as WorkplacePosition,
    { id: 2, name: 'Leader' } as WorkplacePosition,
];

const baseProps = () => ({
    shiftId: '1',
    shiftDate: '2026-06-26',
    shiftStatus: 'in_progress',
    groupId: '1',
    attendanceScope: 'approved' as const,
    title: 'Staff',
    userIds: ['1'],
    users: { '1': buildUser() },
    userIsFixed: { '1': true },
    userHasAbsentNotice: {},
    userStatus: { '1': 'approved' },
    shiftSalary: 1000,
    leaderBonusByUserId: {},
    userPhoneNumbers: {},
    userShiftMeta: {} as Record<string, UserShiftTableMeta>,
    onUserShiftMetaChange: vi.fn(),
    positions,
    onMove: vi.fn(),
    isReadOnly: false,
});

const ROW_NAME_BY_ID: Record<string, string> = { '1': 'Yamada Taro', '2': 'Tanaka' };
const rowFor = (userId: string) => screen.getByText(ROW_NAME_BY_ID[userId]).closest('tr') as HTMLElement;

describe('AssignmentTable', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        chatIntentBox.current = null;
        authBox.current = { user: { id: 999 } };
        routerMock.query = {};
        usePermissionsMock.mockReturnValue({
            SHIFT_CHECK_IN: true,
            SHIFT_UPDATE_OVERTIME: true,
            SHIFT_UPDATE_SALARY: true,
            CHAT_CREATE: false,
            CHAT_VIEW: false,
        });
        useFindDirectConversationMutationMock.mockReturnValue({
            mutateAsync: findDirectConversationMutateAsyncMock,
            isPending: false,
        });
        findDirectConversationMutateAsyncMock.mockResolvedValue({ data: { conversation_id: null } });
        useUpdateAllowOvertimeMutationMock.mockReturnValue({
            mutateAsync: updateAllowOvertimeMutateAsyncMock,
            isPending: false,
        });
        updateAllowOvertimeMutateAsyncMock.mockResolvedValue(undefined);
        useUpdateSalaryMutationMock.mockReturnValue({ mutateAsync: updateSalaryMutateAsyncMock, isPending: false });
        updateSalaryMutateAsyncMock.mockResolvedValue(undefined);
    });

    it('shows the title and fixed/temp counts', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userIds={['1', '2']}
                users={{ '1': buildUser(), '2': buildUser({ id: 2, name: 'Tanaka' }) }}
                userIsFixed={{ '1': true, '2': false }}
            />,
        );

        expect(screen.getByText('Staff')).toBeInTheDocument();
        expect(screen.getByText('Fixed: 1')).toBeInTheDocument();
        expect(screen.getByText('Temp: 1')).toBeInTheDocument();
    });

    it('shows the fixed schedule position of a user, with a dash when there is none', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userIds={['1', '2']}
                users={{ '1': buildUser(), '2': buildUser({ id: 2, name: 'Tanaka' }) }}
                userIsFixed={{ '1': true, '2': false }}
                userFixedSchedulePosition={{ '1': 'Picking' }}
            />,
        );

        expect(screen.getByText('Picking')).toBeInTheDocument();
        const rows = screen.getAllByRole('row').filter((row) => row.getAttribute('data-row-id') === '2');
        expect(rows[0]).toHaveTextContent('-');
    });

    it('sorts fixed users before temp users, then by position sort order, then original order', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userIds={['1', '2', '3']}
                users={{
                    '1': buildUser({ id: 1, name: 'TempUser' }),
                    '2': buildUser({ id: 2, name: 'FixedUser' }),
                    '3': buildUser({ id: 3, name: 'FixedUser2' }),
                }}
                userIsFixed={{ '1': false, '2': true, '3': true }}
                userPositionMeta={{
                    '2': { groupId: '1', name: 'Staff', sortOrder: 2 },
                    '3': { groupId: '1', name: 'Staff', sortOrder: 1 },
                }}
            />,
        );

        const names = screen
            .getAllByRole('row')
            .slice(1)
            .map((row) => within(row).getByText(/User/).textContent);
        expect(names).toEqual(['FixedUser2', 'FixedUser', 'TempUser']);
    });

    it('filters records by the search query against name/email/staff no/phone', async () => {
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                userIds={['1', '2']}
                users={{
                    '1': buildUser(),
                    '2': buildUser({ id: 2, name: 'Tanaka', staff_no: '002', email: 'tanaka@example.com' }),
                }}
            />,
        );

        await user.type(screen.getByLabelText('Search...'), 'tanaka');

        expect(screen.queryByText('Yamada Taro')).not.toBeInTheDocument();
        expect(screen.getByText('Tanaka')).toBeInTheDocument();
    });

    it('clears the search query when the clear button is clicked', async () => {
        const user = userEvent.setup();
        render(<AssignmentTable {...baseProps()} />);

        await user.type(screen.getByLabelText('Search...'), 'xyz');
        expect(screen.queryByText('Yamada Taro')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'clear-search' }));

        expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
    });

    it('shows an absent notice badge and the absence warning for the matching user', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userHasAbsentNotice={{ '1': true }}
                users={{ '1': buildUser({ absence_count: 3 }) }}
            />,
        );

        expect(screen.getByText('Absent Notice')).toBeInTheDocument();
        expect(screen.getByTitle('absence-warning')).toHaveTextContent('3');
    });

    it.each([
        ['pending', 'Documents incomplete'],
        ['wait_for_approval', 'Documents awaiting approval'],
    ])('shows a warning icon for an assigned user whose papers are %s', (paperStatus, label) => {
        render(<AssignmentTable {...baseProps()} users={{ '1': buildUser({ paper_status: paperStatus }) }} />);

        expect(screen.getByTitle(label)).toBeInTheDocument();
    });

    it('names the documents an assigned user still owes in the warning', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                users={{
                    '1': buildUser({
                        paper_status: 'pending',
                        outstanding_document_steps: ['student_card', 'work_permit'],
                    }),
                }}
            />,
        );

        expect(screen.getByTitle('Documents incomplete: Student card, Work permit stamp')).toBeInTheDocument();
    });

    it('shows no paper warning for an assigned user whose papers are approved', () => {
        render(<AssignmentTable {...baseProps()} users={{ '1': buildUser({ paper_status: 'approved' }) }} />);

        expect(screen.queryByTitle(/Documents incomplete/)).not.toBeInTheDocument();
        expect(screen.queryByTitle('Documents awaiting approval')).not.toBeInTheDocument();
    });

    it('shows the position column with a dash fallback when showPositionColumn is true', () => {
        render(<AssignmentTable {...baseProps()} showPositionColumn />);

        expect(screen.getByText('Position')).toBeInTheDocument();
        expect(rowFor('1')).toHaveTextContent('-');
    });

    it('does not render the position column when showPositionColumn is false', () => {
        render(<AssignmentTable {...baseProps()} />);

        expect(screen.queryByText('Position')).not.toBeInTheDocument();
    });

    it('shows a dash for phone when missing and the phone number when present', () => {
        const { rerender } = render(<AssignmentTable {...baseProps()} />);
        expect(rowFor('1')).toHaveTextContent('-');

        rerender(<AssignmentTable {...baseProps()} userPhoneNumbers={{ '1': '090-1234-5678' }} />);
        expect(rowFor('1')).toHaveTextContent('090-1234-5678');
    });

    it('shows a dash for salary when the row is unpersisted (no shiftUserId)', () => {
        render(<AssignmentTable {...baseProps()} />);

        const salaryHeaderIndex = screen
            .getAllByRole('columnheader')
            .findIndex((h) => h.textContent === 'Salary per hour');
        const cell = within(rowFor('1')).getAllByRole('cell')[salaryHeaderIndex];
        expect(cell).toHaveTextContent('-');
    });

    it('uses the shift base salary when the row is persisted with no overrides', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }}
                shiftSalary={1200}
            />,
        );

        expect(rowFor('1')).toHaveTextContent('1200');
    });

    it('adds the leader bonus on top of the base salary and marks it with a star', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }}
                shiftSalary={1000}
                leaderBonusByUserId={{ '1': 200 }}
            />,
        );

        expect(rowFor('1')).toHaveTextContent('1200 ★');
    });

    it('overrides everything with a custom salaryPerHour and does not show the leader star', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11, salaryPerHour: 5000 } as UserShiftTableMeta }}
                shiftSalary={1000}
                leaderBonusByUserId={{ '1': 200 }}
            />,
        );

        expect(rowFor('1')).toHaveTextContent('5000');
        expect(rowFor('1')).not.toHaveTextContent('★');
    });

    it('opens the salary edit modal and updates the salary on submit', async () => {
        const onUserShiftMetaChange = vi.fn();
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }}
                onUserShiftMetaChange={onUserShiftMetaChange}
            />,
        );

        await user.click(within(rowFor('1')).getByRole('button', { name: 'action-icon' }));
        expect(screen.getByTestId('modal')).toBeInTheDocument();

        const saveButtons = screen.getAllByRole('button', { name: 'Save' });
        await user.click(saveButtons[saveButtons.length - 1]);

        expect(updateSalaryMutateAsyncMock).toHaveBeenCalledWith({ shiftUserId: 11, salary: 1500 });
        expect(onUserShiftMetaChange).toHaveBeenCalledWith('1', { salaryPerHour: 1500 });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully updated user salary');
    });

    /**
     * shift.salary is granted per workplace but the edit action is gated by the flat
     * permission list, so a 403 can come back from a row the admin can still click.
     * Only 422 reaches FormHelper, so the modal used to close over a silent failure.
     */
    it('explains an out-of-scope workplace when the salary update is refused', async () => {
        updateSalaryMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 403, message: 'CHECK_IN_WORKPLACE_OUT_OF_SCOPE', data: {} }),
        );
        const onUserShiftMetaChange = vi.fn();
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }}
                onUserShiftMetaChange={onUserShiftMetaChange}
            />,
        );

        await user.click(within(rowFor('1')).getByRole('button', { name: 'action-icon' }));
        const saveButtons = screen.getAllByRole('button', { name: 'Save' });
        await user.click(saveButtons[saveButtons.length - 1]);

        expect(showErrorMock).toHaveBeenCalledWith('error|You do not have permission to manage this workplace');
        expect(onUserShiftMetaChange).not.toHaveBeenCalled();
        expect(showSuccessMock).not.toHaveBeenCalled();
    });

    it('keeps validation errors on the salary form without a toast', async () => {
        updateSalaryMutateAsyncMock.mockRejectedValue(
            new ApiValidationError({
                status: 422,
                message: 'E_UNPROCESSABLE_ENTITY',
                data: { salary: ['invalid'] },
            }),
        );
        const user = userEvent.setup();
        render(<AssignmentTable {...baseProps()} userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }} />);

        await user.click(within(rowFor('1')).getByRole('button', { name: 'action-icon' }));
        const saveButtons = screen.getAllByRole('button', { name: 'Save' });
        await user.click(saveButtons[saveButtons.length - 1]);

        expect(setFormErrorMock).toHaveBeenCalled();
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('disables the salary edit action when the user lacks SHIFT_UPDATE_SALARY', () => {
        usePermissionsMock.mockReturnValue({
            SHIFT_CHECK_IN: true,
            SHIFT_UPDATE_OVERTIME: true,
            SHIFT_UPDATE_SALARY: false,
        });
        render(<AssignmentTable {...baseProps()} userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }} />);

        expect(within(rowFor('1')).getByRole('button', { name: 'action-icon' })).toBeDisabled();
    });

    it('formats the confirmedAt timestamp and falls back to a dash', () => {
        const { rerender } = render(<AssignmentTable {...baseProps()} />);
        expect(rowFor('1')).toHaveTextContent('-');

        rerender(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { confirmedAt: '2026-06-20T00:00:00Z' } as UserShiftTableMeta }}
            />,
        );
        expect(rowFor('1')).toHaveTextContent('FORMATTED(2026-06-20T00:00:00Z)');
    });

    it('shows a dash for overtime allowed when it has not been set', () => {
        render(<AssignmentTable {...baseProps()} />);

        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });

    it('toggles overtime allowed, persists it, and shows a success notice', async () => {
        const onUserShiftMetaChange = vi.fn();
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11, isOvertimeAllowed: false } as UserShiftTableMeta }}
                onUserShiftMetaChange={onUserShiftMetaChange}
            />,
        );

        await user.click(screen.getByRole('switch'));

        expect(updateAllowOvertimeMutateAsyncMock).toHaveBeenCalledWith({ shiftUserId: 11, isAllowed: 1 });
        expect(onUserShiftMetaChange).toHaveBeenCalledWith('1', { isOvertimeAllowed: true });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully update overtime status');
    });

    /**
     * handleOvertimeChange had no try/catch, so a refused toggle produced an unhandled
     * rejection and left the switch snapping back with no explanation.
     */
    it('reports a refused overtime toggle and leaves the row unchanged', async () => {
        updateAllowOvertimeMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 403, message: 'CHECK_IN_WORKPLACE_OUT_OF_SCOPE', data: {} }),
        );
        const onUserShiftMetaChange = vi.fn();
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11, isOvertimeAllowed: false } as UserShiftTableMeta }}
                onUserShiftMetaChange={onUserShiftMetaChange}
            />,
        );

        await user.click(screen.getByRole('switch'));

        expect(showErrorMock).toHaveBeenCalledWith('error|You do not have permission to manage this workplace');
        expect(onUserShiftMetaChange).not.toHaveBeenCalled();
        expect(showSuccessMock).not.toHaveBeenCalled();
    });

    it('falls back to a generic message when the overtime toggle fails for another reason', async () => {
        updateAllowOvertimeMutateAsyncMock.mockRejectedValue(new Error('network'));
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11, isOvertimeAllowed: false } as UserShiftTableMeta }}
            />,
        );

        await user.click(screen.getByRole('switch'));

        expect(showErrorMock).toHaveBeenCalledWith('error|Could not update overtime status');
    });

    it('disables the overtime switch and locks pointer events while the shift is marked absent', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{
                    '1': { shiftUserId: 11, isOvertimeAllowed: true, isAbsent: true } as UserShiftTableMeta,
                }}
            />,
        );

        expect(screen.getByRole('switch')).toBeDisabled();
    });

    it('shows a disabled Check in button for a persisted, approved, non-absent row', () => {
        render(<AssignmentTable {...baseProps()} userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }} />);

        expect(screen.getByRole('button', { name: 'Check in' })).toBeEnabled();
    });

    it('shows an absent-shift disabled button instead of Check in when the row is marked absent', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{ '1': { shiftUserId: 11, isAbsent: true } as UserShiftTableMeta }}
            />,
        );

        const buttons = screen.getAllByRole('button', { name: 'Check in' });
        expect(buttons.some((b) => b.hasAttribute('disabled'))).toBe(true);
    });

    it('greys out the recorded start time instead of the Check in button when the row is absent', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                userShiftMeta={{
                    '1': {
                        shiftUserId: 11,
                        isAbsent: true,
                        checkInStart: '2026-06-26 09:00:00',
                    } as UserShiftTableMeta,
                }}
            />,
        );

        expect(screen.getByText('FORMATTED(2026-06-26 09:00:00)')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check in' })).not.toBeInTheDocument();
    });

    it('greys out the recorded end time instead of the Check out button when the row is absent', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                shiftStatus="ended"
                userShiftMeta={{
                    '1': {
                        shiftUserId: 11,
                        isAbsent: true,
                        checkInStart: '2026-06-26 09:00:00',
                        checkInEnd: '2026-06-26 18:00:00',
                    } as UserShiftTableMeta,
                }}
            />,
        );

        expect(screen.getByText('FORMATTED(2026-06-26 18:00:00)')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check out' })).not.toBeInTheDocument();
    });

    it('opens the check-in modal when Check in is clicked', async () => {
        const user = userEvent.setup();
        render(<AssignmentTable {...baseProps()} userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }} />);

        await user.click(screen.getByRole('button', { name: 'Check in' }));

        expect(screen.getByTestId('check-in-out-modal')).toHaveTextContent('check-in');
    });

    it('shows "Not yet checked in" for Check out when there is no check-in yet', () => {
        render(
            <AssignmentTable
                {...baseProps()}
                shiftStatus="ended"
                userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }}
            />,
        );

        expect(screen.getByText('Not yet checked in')).toBeInTheDocument();
    });

    it('shows a Check out button once checked in and the shift has ended', async () => {
        const user = userEvent.setup();
        render(
            <AssignmentTable
                {...baseProps()}
                shiftStatus="ended"
                userShiftMeta={{ '1': { shiftUserId: 11, checkInStart: '2026-06-26 09:00:00' } as UserShiftTableMeta }}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Check out' }));

        expect(screen.getByTestId('check-in-out-modal')).toHaveTextContent('check-out');
    });

    it('renders error messages using the user name and disables via row background for errored users', () => {
        render(<AssignmentTable {...baseProps()} errors={{ '1': ['Overlap detected'] }} />);

        expect(screen.getByText('Yamada Taro: Overlap detected')).toBeInTheDocument();
    });

    it('shows the Assign menu with bulk target options excluding the current group', async () => {
        const user = userEvent.setup();
        const onMove = vi.fn();
        render(<AssignmentTable {...baseProps()} onMove={onMove} groupId="1" />);

        await user.click(within(rowFor('1')).getByRole('button', { name: 'Assign' }));
        await user.click(within(rowFor('1')).getByRole('menuitem', { name: 'Leader' }));

        expect(onMove).toHaveBeenCalledWith(['1'], '2');
    });

    it('includes Register list and Rejected list in bulk target options unless already in that group', () => {
        render(<AssignmentTable {...baseProps()} groupId="1" />);

        const menuItems = within(rowFor('1')).getAllByRole('menuitem');
        expect(menuItems.map((i) => i.textContent)).toEqual(['Leader', 'Register list', 'Rejected list']);
    });

    it('shows a remove icon only for unpersisted rows when onRemove is provided', () => {
        const { rerender } = render(<AssignmentTable {...baseProps()} onRemove={vi.fn()} />);
        expect(within(rowFor('1')).queryByRole('button', { name: 'Remove' })).toBeInTheDocument();

        rerender(
            <AssignmentTable
                {...baseProps()}
                onRemove={vi.fn()}
                userShiftMeta={{ '1': { shiftUserId: 11 } as UserShiftTableMeta }}
            />,
        );
        expect(within(rowFor('1')).queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
    });

    it('calls onRemove with the row id when the remove icon is clicked', async () => {
        const user = userEvent.setup();
        const onRemove = vi.fn();
        render(<AssignmentTable {...baseProps()} onRemove={onRemove} />);

        await user.click(within(rowFor('1')).getByRole('button', { name: 'Remove' }));

        expect(onRemove).toHaveBeenCalledWith(['1']);
    });

    it('bulk-assigns selected rows via onBulkAssign when provided, then clears selection', async () => {
        const user = userEvent.setup();
        const onBulkAssign = vi.fn();
        const onMove = vi.fn();
        render(
            <AssignmentTable
                {...baseProps()}
                onBulkAssign={onBulkAssign}
                onMove={onMove}
                userIds={['1', '2']}
                users={{ '1': buildUser(), '2': buildUser({ id: 2, name: 'Tanaka' }) }}
            />,
        );

        await user.click(screen.getByLabelText('select-1'));
        await user.click(screen.getByRole('button', { name: 'Assign to...' }));
        await user.click(screen.getAllByRole('menuitem', { name: 'Leader' })[0]);

        expect(onBulkAssign).toHaveBeenCalledWith(['1'], '1', '2');
        expect(onMove).not.toHaveBeenCalled();
    });

    it('falls back to onMove for bulk assignment when onBulkAssign is not provided', async () => {
        const user = userEvent.setup();
        const onMove = vi.fn();
        render(<AssignmentTable {...baseProps()} onMove={onMove} />);

        await user.click(screen.getByLabelText('select-1'));
        await user.click(screen.getByRole('button', { name: 'Assign to...' }));
        await user.click(screen.getAllByRole('menuitem', { name: 'Leader' })[0]);

        expect(onMove).toHaveBeenCalledWith(['1'], '2');
    });

    it('disables the Assign to... button when nothing is selected or the table is read-only', () => {
        const { rerender } = render(<AssignmentTable {...baseProps()} />);
        expect(screen.getByRole('button', { name: 'Assign to...' })).toBeDisabled();

        rerender(<AssignmentTable {...baseProps()} isReadOnly />);
        expect(screen.getByRole('button', { name: 'Assign to...' })).toBeDisabled();
    });

    it('shows the Add button only when onAddUsers is provided and the table is not read-only', () => {
        const { rerender } = render(<AssignmentTable {...baseProps()} onAddUsers={vi.fn()} />);
        expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();

        rerender(<AssignmentTable {...baseProps()} onAddUsers={vi.fn()} isReadOnly />);
        expect(screen.queryByRole('button', { name: 'Add' })).not.toBeInTheDocument();
    });

    it('hides the actions column entirely when isReadOnly', () => {
        render(<AssignmentTable {...baseProps()} isReadOnly onRemove={vi.fn()} />);

        expect(within(rowFor('1')).queryByRole('button', { name: 'Assign' })).not.toBeInTheDocument();
        expect(within(rowFor('1')).queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
    });

    it('passes no selection props to the table when isReadOnly', () => {
        render(<AssignmentTable {...baseProps()} isReadOnly />);

        const lastCall = dataTableSpy.mock.calls[dataTableSpy.mock.calls.length - 1][0];
        expect(lastCall.selectedRecords).toBeUndefined();
        expect(lastCall.onSelectedRecordsChange).toBeUndefined();
    });

    describe('attendance filter', () => {
        const usersWithAttendance = () => ({
            '1': buildUser({ id: 1, name: 'Yamada Taro' }),
            '2': buildUser({ id: 2, name: 'Tanaka' }),
        });

        it('renders the attendance filter and narrows rows to unconfirmed entries', async () => {
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    userShiftMeta={{
                        '1': { confirmedAt: null } as UserShiftTableMeta,
                        '2': { confirmedAt: '2026-06-20T00:00:00Z' } as UserShiftTableMeta,
                    }}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Not confirmed' }));

            expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
            expect(screen.queryByText('Tanaka')).not.toBeInTheDocument();
        });

        it('narrows rows to entries without a check-in start time', async () => {
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    userShiftMeta={{
                        '1': { checkInStart: null } as UserShiftTableMeta,
                        '2': { checkInStart: '2026-06-26T09:00:00Z' } as UserShiftTableMeta,
                    }}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Not checked in' }));

            expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
            expect(screen.queryByText('Tanaka')).not.toBeInTheDocument();
        });

        it('composes the attendance filter with the text search', async () => {
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    userShiftMeta={{
                        '1': { confirmedAt: null } as UserShiftTableMeta,
                        '2': { confirmedAt: null } as UserShiftTableMeta,
                    }}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Not confirmed' }));
            await user.type(screen.getByLabelText('Search...'), 'tanaka');

            expect(screen.queryByText('Yamada Taro')).not.toBeInTheDocument();
            expect(screen.getByText('Tanaka')).toBeInTheDocument();
        });

        it('renders no rows when the attendance filter matches nothing', async () => {
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1']}
                    users={{ '1': buildUser() }}
                    userShiftMeta={{ '1': { confirmedAt: '2026-06-20T00:00:00Z' } as UserShiftTableMeta }}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Not confirmed' }));

            expect(screen.queryByText('Yamada Taro')).not.toBeInTheDocument();
            const lastCall = dataTableSpy.mock.calls[dataTableSpy.mock.calls.length - 1][0];
            expect(lastCall.data).toEqual([]);
        });

        it('drops hidden rows from the selection when the filter changes', async () => {
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    userShiftMeta={{
                        '1': { confirmedAt: null } as UserShiftTableMeta,
                        '2': { confirmedAt: '2026-06-20T00:00:00Z' } as UserShiftTableMeta,
                    }}
                />,
            );

            await user.click(screen.getByLabelText('select-2'));
            expect(screen.getByLabelText('select-2')).toBeChecked();

            await user.click(screen.getByRole('button', { name: 'Not confirmed' }));
            await user.click(screen.getByRole('button', { name: 'All' }));

            expect(screen.getByLabelText('select-2')).not.toBeChecked();
        });

        it('seeds the filter from initialAttendanceFilter', () => {
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    userShiftMeta={{
                        '1': { confirmedAt: null } as UserShiftTableMeta,
                        '2': { confirmedAt: '2026-06-20T00:00:00Z' } as UserShiftTableMeta,
                    }}
                    initialAttendanceFilter="unconfirmed"
                />,
            );

            expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
            expect(screen.queryByText('Tanaka')).not.toBeInTheDocument();
        });

        it('resets to "all" on client-side navigation to a different shift even when the new URL has no filter for this table', () => {
            // Regression test: the component instance is reused (not remounted) across a
            // client-side navigation between two shift detail pages. Landing on a clean URL
            // for the NEW shift must show 'all', not silently keep the PREVIOUS shift's
            // filter just because resolveInitialAttendanceFilter can't tell "no value for
            // this table" apart from "no value at all" (initialAttendanceFilter is undefined
            // in both cases).
            const { rerender } = render(
                <AssignmentTable
                    {...baseProps()}
                    shiftId="1"
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    initialAttendanceFilter="unconfirmed"
                />,
            );
            expect(screen.getByRole('button', { name: 'Not confirmed', pressed: true })).toBeInTheDocument();

            rerender(
                <AssignmentTable
                    {...baseProps()}
                    shiftId="2"
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    initialAttendanceFilter={undefined}
                />,
            );

            expect(screen.getByRole('button', { name: 'All', pressed: true })).toBeInTheDocument();
        });

        it('writes the selected filter into ?attendance=&attendanceGroup= via shallow navigation so it is shareable', async () => {
            const user = userEvent.setup();
            render(<AssignmentTable {...baseProps()} userIds={['1', '2']} users={usersWithAttendance()} />);

            await user.click(screen.getByRole('button', { name: 'Not confirmed' }));

            // baseProps() sets attendanceScope: 'approved' — tagging the write with this
            // table's own stable scope (rather than a bare ?attendance=) is what lets the
            // page seed only the matching table(s) on re-render instead of forcing every
            // AssignmentTable instance on the page to re-filter.
            expect(routerReplaceMock).toHaveBeenCalledWith(
                { pathname: '/manage/shift/[id]', query: { attendance: 'unconfirmed', attendanceGroup: 'approved' } },
                undefined,
                { shallow: true },
            );
        });

        it('drops both attendance query params when resetting the table that currently owns them', async () => {
            routerMock.query = { attendance: 'not_checked_in', attendanceGroup: 'approved' };
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                    initialAttendanceFilter="not_checked_in"
                />,
            );

            await user.click(screen.getByRole('button', { name: 'All' }));

            expect(routerReplaceMock).toHaveBeenCalledWith({ pathname: '/manage/shift/[id]', query: {} }, undefined, {
                shallow: true,
            });
        });

        it("leaves a sibling table's still-active filter in the URL when resetting to all", async () => {
            // Regression test: this table's own scope is 'approved' (baseProps), but the URL
            // currently holds a pair written by a different scope ('rejected' — e.g. that
            // table's filter is still active). Resetting THIS table to 'all' must not clear
            // it — otherwise a copied link would stop reproducing the other table's filter.
            routerMock.query = { attendance: 'unconfirmed', attendanceGroup: 'rejected' };
            const user = userEvent.setup();
            render(<AssignmentTable {...baseProps()} userIds={['1', '2']} users={usersWithAttendance()} />);

            await user.click(screen.getByRole('button', { name: 'All' }));

            expect(routerReplaceMock).not.toHaveBeenCalled();
        });

        it("does not touch another table's selection or filter when this table's filter changes", async () => {
            // Regression test for the cross-table bleed: writing ?attendance= from one
            // AssignmentTable must not itself mutate router.query for a sibling table — the
            // page is responsible for scoping initialAttendanceFilter per AttendanceScope via
            // resolveInitialAttendanceFilter, and this table must only ever write its own
            // scope into the URL.
            const user = userEvent.setup();
            render(
                <AssignmentTable
                    {...baseProps()}
                    attendanceScope="rejected"
                    userIds={['1', '2']}
                    users={usersWithAttendance()}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Not checked in' }));

            const [query] = routerReplaceMock.mock.calls[0];
            expect(query.query.attendanceGroup).toBe('rejected');
        });
    });

    describe('message action', () => {
        const permissionsWithChat = (canCreate: boolean, canView: boolean = canCreate) => ({
            SHIFT_CHECK_IN: true,
            SHIFT_UPDATE_OVERTIME: true,
            SHIFT_UPDATE_SALARY: true,
            CHAT_CREATE: canCreate,
            CHAT_VIEW: canView,
        });

        it('renders the message action with both chat permissions and hides it without', () => {
            usePermissionsMock.mockReturnValue(permissionsWithChat(true, true));
            const { rerender } = render(<AssignmentTable {...baseProps()} />);
            expect(within(rowFor('1')).getByRole('button', { name: 'Message' })).toBeInTheDocument();

            usePermissionsMock.mockReturnValue(permissionsWithChat(false, false));
            rerender(<AssignmentTable {...baseProps()} />);
            expect(within(rowFor('1')).queryByRole('button', { name: 'Message' })).not.toBeInTheDocument();
        });

        it('hides the message action when chat.create is granted without chat.view', () => {
            // The lookup endpoint and AdminChatPortal both require chat.view, so a role with
            // only chat.create must not see a shortcut that would always 403.
            usePermissionsMock.mockReturnValue(permissionsWithChat(true, false));
            render(<AssignmentTable {...baseProps()} />);
            expect(within(rowFor('1')).queryByRole('button', { name: 'Message' })).not.toBeInTheDocument();
        });

        it("hides the message action on the current admin's own row", () => {
            // A 1:1 lookup against yourself collapses FindDirectConversationAction's two
            // participant clauses into one and can open an unrelated existing chat instead
            // of "no match" — guard the row rather than relying on the backend alone.
            usePermissionsMock.mockReturnValue(permissionsWithChat(true, true));
            authBox.current = { user: { id: 1 } };
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={{ '1': buildUser(), '2': buildUser({ id: 2, name: 'Tanaka' }) }}
                />,
            );

            expect(within(rowFor('1')).queryByRole('button', { name: 'Message' })).not.toBeInTheDocument();
            expect(within(rowFor('2')).getByRole('button', { name: 'Message' })).toBeInTheDocument();
        });

        it('writes an open intent when a direct conversation is found', async () => {
            usePermissionsMock.mockReturnValue(permissionsWithChat(true));
            findDirectConversationMutateAsyncMock.mockResolvedValue({ data: { conversation_id: 55 } });
            const user = userEvent.setup();
            render(<AssignmentTable {...baseProps()} />);

            await user.click(within(rowFor('1')).getByRole('button', { name: 'Message' }));

            expect(findDirectConversationMutateAsyncMock).toHaveBeenCalledWith(1);
            expect(setChatIntentMock).toHaveBeenCalledWith({ kind: 'open', conversationId: 55 });
        });

        it('writes a create-direct intent when no direct conversation exists', async () => {
            usePermissionsMock.mockReturnValue(permissionsWithChat(true));
            findDirectConversationMutateAsyncMock.mockResolvedValue({ data: { conversation_id: null } });
            const user = userEvent.setup();
            render(<AssignmentTable {...baseProps()} />);

            await user.click(within(rowFor('1')).getByRole('button', { name: 'Message' }));

            expect(setChatIntentMock).toHaveBeenCalledWith(
                expect.objectContaining({ kind: 'create-direct', user: expect.objectContaining({ id: 1 }) }),
            );
        });

        it('shows an error notification when the lookup fails', async () => {
            usePermissionsMock.mockReturnValue(permissionsWithChat(true));
            findDirectConversationMutateAsyncMock.mockRejectedValue(new Error('network error'));
            const user = userEvent.setup();
            render(<AssignmentTable {...baseProps()} />);

            await user.click(within(rowFor('1')).getByRole('button', { name: 'Message' }));

            expect(showErrorMock).toHaveBeenCalled();
            expect(setChatIntentMock).not.toHaveBeenCalled();
        });
    });

    describe('weekly hours column', () => {
        it('shows each row the weekly work load of its own user', () => {
            render(
                <AssignmentTable
                    {...baseProps()}
                    userIds={['1', '2']}
                    users={{ '1': buildUser(), '2': buildUser({ id: 2, name: 'Tanaka' }) }}
                    userStatus={{ '1': 'approved', '2': 'approved' }}
                    weeklyWorkLoads={{
                        '2': { projected_hours: 30, max_hour_per_week: 28 } as ShiftWeeklyWorkLoad,
                    }}
                />,
            );

            expect(screen.getByRole('columnheader', { name: 'Weekly hours' })).toBeInTheDocument();
            expect(within(rowFor('2')).getByTestId('weekly-work-load')).toHaveTextContent('30/28');
            expect(within(rowFor('1')).getByTestId('weekly-work-load')).toHaveTextContent('no-work-load');
        });

        it('explains why a blocked user can only be moved to the rejected list', () => {
            render(
                <AssignmentTable
                    {...baseProps()}
                    groupId="pending"
                    userIds={['1', '2']}
                    users={{ '1': buildUser(), '2': buildUser({ id: 2, name: 'Tanaka' }) }}
                    userStatus={{ '1': 'pending', '2': 'pending' }}
                    weeklyWorkLoads={{
                        '1': { is_blocked: false, is_shift_counted: false } as ShiftWeeklyWorkLoad,
                        '2': { is_blocked: true, is_shift_counted: false } as ShiftWeeklyWorkLoad,
                    }}
                />,
            );

            const blockedRow = within(rowFor('2'));
            expect(
                blockedRow.getByText('Over the weekly work-hour limit: can only be moved to the rejected list'),
            ).toBeInTheDocument();
            expect(blockedRow.getByRole('menuitem', { name: 'Staff' })).toBeDisabled();
            expect(blockedRow.getByRole('menuitem', { name: 'Rejected list' })).not.toBeDisabled();

            const allowedRow = within(rowFor('1'));
            expect(
                allowedRow.queryByText('Over the weekly work-hour limit: can only be moved to the rejected list'),
            ).not.toBeInTheDocument();
            expect(allowedRow.getByRole('menuitem', { name: 'Staff' })).not.toBeDisabled();
        });

        it('lets an approved user who went over the limit only reduce hours, not change position', () => {
            render(
                <AssignmentTable
                    {...baseProps()}
                    weeklyWorkLoads={{
                        '1': { is_blocked: false, is_shift_counted: true, status: 'over' } as ShiftWeeklyWorkLoad,
                    }}
                />,
            );

            const row = within(rowFor('1'));
            expect(
                row.getByText(
                    'Approved before going over the weekly work-hour limit: move to the register or rejected list to reduce hours',
                ),
            ).toBeInTheDocument();
            expect(row.getByRole('menuitem', { name: 'Leader' })).toBeDisabled();
            expect(row.getByRole('menuitem', { name: 'Register list' })).not.toBeDisabled();
            expect(row.getByRole('menuitem', { name: 'Rejected list' })).not.toBeDisabled();
        });

        it('does not warn an approved user who is within the limit', () => {
            render(
                <AssignmentTable
                    {...baseProps()}
                    weeklyWorkLoads={{
                        '1': { is_blocked: false, is_shift_counted: true, status: 'near' } as ShiftWeeklyWorkLoad,
                    }}
                />,
            );

            const row = within(rowFor('1'));
            expect(row.queryByText('Approved before going over', { exact: false })).not.toBeInTheDocument();
            expect(row.getByRole('menuitem', { name: 'Leader' })).not.toBeDisabled();
        });

        it('keeps an already assigned user over the limit from going back to the register list', () => {
            render(
                <AssignmentTable
                    {...baseProps()}
                    weeklyWorkLoads={{ '1': { is_blocked: true, is_shift_counted: true } as ShiftWeeklyWorkLoad }}
                />,
            );

            const row = within(rowFor('1'));
            expect(row.getByRole('menuitem', { name: 'Register list' })).toBeDisabled();
            expect(row.getByRole('menuitem', { name: 'Leader' })).toBeDisabled();
            expect(row.getByRole('menuitem', { name: 'Rejected list' })).not.toBeDisabled();
        });
    });
});

describe('resolveInitialAttendanceFilter', () => {
    it('returns the parsed filter when attendanceGroup matches this scope', () => {
        expect(resolveInitialAttendanceFilter('unconfirmed', 'pending', 'pending')).toBe('unconfirmed');
    });

    it('returns undefined (leave local state alone) when attendanceGroup targets a different table', () => {
        expect(resolveInitialAttendanceFilter('unconfirmed', 'pending', 'rejected')).toBeUndefined();
    });

    it('applies a bare ?attendance= with no ?attendanceGroup= to every scope (legacy/external link contract)', () => {
        expect(resolveInitialAttendanceFilter('unconfirmed', undefined, 'pending')).toBe('unconfirmed');
        expect(resolveInitialAttendanceFilter('unconfirmed', undefined, 'approved')).toBe('unconfirmed');
        expect(resolveInitialAttendanceFilter('unconfirmed', undefined, 'rejected')).toBe('unconfirmed');
    });

    it('returns undefined when there is neither an attendance nor an attendanceGroup value', () => {
        expect(resolveInitialAttendanceFilter(undefined, undefined, 'pending')).toBeUndefined();
    });

    it('falls back to "all" for an unrecognized attendance value even when the group matches', () => {
        expect(resolveInitialAttendanceFilter('bogus', 'pending', 'pending')).toBe('all');
    });

    it('treats the split-by-position and combined approved tables as the same stable scope', () => {
        // A link written from a per-position table (scope 'approved') must still apply when
        // the recipient is in combined view, and vice versa — both read/write 'approved'.
        expect(resolveInitialAttendanceFilter('not_checked_in', 'approved', 'approved')).toBe('not_checked_in');
    });
});
