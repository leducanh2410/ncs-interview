import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SalaryRequestDetailPage from '@/pages/manage/salary-request/[id].page';

const {
    approveMutateMock,
    confirmPaidMutateMock,
    useDetailQueryMock,
    usePermissionsMock,
    showSuccessMock,
    showErrorMock,
    routerMock,
} = vi.hoisted(() => ({
    approveMutateMock: vi.fn(),
    confirmPaidMutateMock: vi.fn(),
    useDetailQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    routerMock: { query: { id: '42' } as Record<string, string> },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuCheck: () => React.createElement('span', null, 'check-icon'),
    LuCircleDollarSign: () => React.createElement('span', null, 'money-icon'),
    LuX: () => React.createElement('span', null, 'x-icon'),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement(
            'button',
            { onClick, 'disabled': disabled || loading, 'data-loading': !!loading },
            children,
        ),
    Card: ({ children }: any) => React.createElement('section', null, children),
    CardSection: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial: boolean) => {
        const [opened, setOpened] = React.useState(initial);
        return [opened, { open: () => setOpened(true), close: () => setOpened(false) }];
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/salary-request/api', () => ({
    useGetSalaryRequestDetailQuery: (...args: unknown[]) => useDetailQueryMock(...args),
    useApproveSalaryRequestMutation: () => ({ mutateAsync: approveMutateMock, isPending: false }),
    useConfirmPaidSalaryRequestMutation: () => ({ mutateAsync: confirmPaidMutateMock, isPending: false }),
}));

vi.mock('@/pages/manage/salary-request/partials/salary_reject_modal', () => ({
    default: ({ opened, id }: any) => (opened ? React.createElement('div', null, `reject-modal:${id}`) : null),
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
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

vi.mock('@/helpers/common.helper', () => ({
    default: { formatNumber: (value: number) => new Intl.NumberFormat('en-US').format(value) },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string | null) => (value ? `fmt(${value})` : ''),
        formatMinuteToHour: (minutes: number) => `${minutes / 60}h`,
    },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' }, MANAGE_SALARY_REQUEST: { path: '/manage/salary-request' } },
}));

const ALL_PERMISSIONS = {
    SALARY_REQUEST_LIST: true,
    SALARY_REQUEST_APPROVE: true,
    SALARY_REQUEST_APPROVE_ACCOUNTANT: true,
    SALARY_REQUEST_REJECT: true,
    SALARY_REQUEST_CONFIRM_PAID: true,
};

const detail = (overrides: Record<string, any> = {}) => ({
    id: 42,
    user: { id: 7, name: 'YAMADA TARO', staff_no: '0042' },
    created_at: '2026-05-10 12:00:00',
    max_amount: 66000,
    amount: 51000,
    type: 'transfer',
    status: 'wait_for_approval',
    reject_reason: null,
    approver: null,
    approved_at: null,
    accountant_approver: null,
    accountant_approved_at: null,
    rejected_at: null,
    paid_at: null,
    received_at: null,
    returned_at: null,
    details: [
        {
            id: 1,
            amount: 50000,
            salary: { month: '2026-03', total_amount: 50000, total_minutes: 3000, min_salary: 1000, is_finalized: 1 },
        },
    ],
    ...overrides,
});

const withDetail = (data: Record<string, any> | null = detail(), isLoading = false) =>
    useDetailQueryMock.mockReturnValue({ data: data ? { data } : undefined, isLoading });

const button = (name: string) => screen.getByRole('button', { name });

describe('SalaryRequestDetailPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = { id: '42' };
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS });
        approveMutateMock.mockResolvedValue({ data: null });
        confirmPaidMutateMock.mockResolvedValue({ data: null });
        withDetail();
    });

    it('asks for the request named in the URL', () => {
        render(<SalaryRequestDetailPage />);

        expect(useDetailQueryMock).toHaveBeenCalledWith('42', true);
    });

    it('shows only the heading while the request is loading', () => {
        withDetail(null, true);
        render(<SalaryRequestDetailPage />);

        expect(screen.getByRole('heading', { name: 'Salary request detail' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    });

    it('shows only the heading when the admin may not read the request', () => {
        usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_LIST: false });
        withDetail(null);
        render(<SalaryRequestDetailPage />);

        expect(useDetailQueryMock).toHaveBeenCalledWith('42', false);
        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    });

    it('renders the request summary', () => {
        render(<SalaryRequestDetailPage />);

        expect(screen.getByText('YAMADA TARO (0042)')).toBeInTheDocument();
        expect(screen.getByText('fmt(2026-05-10 12:00:00)')).toBeInTheDocument();
        expect(screen.getByText('66,000')).toBeInTheDocument();
        expect(screen.getByText('51,000')).toBeInTheDocument();
        expect(screen.getByText('enum|Transfer')).toBeInTheDocument();
        expect(screen.getByText('enum|Wait for approval')).toBeInTheDocument();
        // Nobody has decided yet.
        expect(screen.getAllByText('-').length).toBeGreaterThanOrEqual(2);
    });

    it('renders each month the request draws from', () => {
        render(<SalaryRequestDetailPage />);

        expect(screen.getByText('2026-03')).toBeInTheDocument();
        expect(screen.getByText('50h')).toBeInTheDocument();
        expect(screen.getByText('1,000')).toBeInTheDocument();
        expect(screen.getByText('Yes')).toBeInTheDocument();
    });

    it('marks an unsettled month as not finalized', () => {
        withDetail(
            detail({
                details: [
                    {
                        id: 1,
                        amount: 1000,
                        salary: {
                            month: '2026-05',
                            total_amount: 20000,
                            total_minutes: 600,
                            min_salary: 1000,
                            is_finalized: 0,
                        },
                    },
                ],
            }),
        );
        render(<SalaryRequestDetailPage />);

        expect(screen.getByText('No')).toBeInTheDocument();
    });

    it('hides the reject reason while the request is still open', () => {
        render(<SalaryRequestDetailPage />);

        expect(screen.queryByText('Reject reason')).not.toBeInTheDocument();
    });

    it('shows the reject reason and reuses the approved-at slot for the rejection time', () => {
        withDetail(detail({ status: 'rejected', reject_reason: 'Too early', rejected_at: '2026-05-11 09:00:00' }));
        render(<SalaryRequestDetailPage />);

        expect(screen.getByText('Reject reason')).toBeInTheDocument();
        expect(screen.getByText('Too early')).toBeInTheDocument();
        expect(screen.getByText('fmt(2026-05-11 09:00:00)')).toBeInTheDocument();
    });

    it('falls back to the expiry time when the transfer was returned', () => {
        withDetail(detail({ status: 'returned', returned_at: '2026-05-20 09:00:00' }));
        render(<SalaryRequestDetailPage />);

        expect(screen.getByText('fmt(2026-05-20 09:00:00)')).toBeInTheDocument();
    });

    it('names both approvers once they have signed off', () => {
        withDetail(
            detail({
                status: 'wait_for_payment',
                approver: { id: 1, name: 'MANAGER ONE' },
                approved_at: '2026-05-11 09:00:00',
                accountant_approver: { id: 2, name: 'ACCOUNTANT TWO' },
                accountant_approved_at: '2026-05-12 09:00:00',
            }),
        );
        render(<SalaryRequestDetailPage />);

        expect(screen.getByText('MANAGER ONE')).toBeInTheDocument();
        expect(screen.getByText('ACCOUNTANT TWO')).toBeInTheDocument();
    });

    describe('approve', () => {
        it('is available at the first stage to an approver', () => {
            render(<SalaryRequestDetailPage />);

            expect(button('Approve')).toBeEnabled();
        });

        it('is closed at the first stage to someone who only signs off as accountant', () => {
            usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_APPROVE: false });
            render(<SalaryRequestDetailPage />);

            expect(button('Approve')).toBeDisabled();
        });

        it('is available at the accountant stage to an accountant', () => {
            withDetail(detail({ status: 'wait_for_approval_accountant' }));
            usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_APPROVE: false });
            render(<SalaryRequestDetailPage />);

            expect(button('Approve')).toBeEnabled();
        });

        it('is closed at the accountant stage to a first-stage approver', () => {
            withDetail(detail({ status: 'wait_for_approval_accountant' }));
            usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_APPROVE_ACCOUNTANT: false });
            render(<SalaryRequestDetailPage />);

            expect(button('Approve')).toBeDisabled();
        });

        it.each(['wait_for_payment', 'paid', 'rejected', 'cancelled'])('is closed once the request is %s', (status) => {
            withDetail(detail({ status }));
            render(<SalaryRequestDetailPage />);

            expect(button('Approve')).toBeDisabled();
        });

        it('reports success', async () => {
            render(<SalaryRequestDetailPage />);

            await userEvent.click(button('Approve'));

            await waitFor(() => expect(approveMutateMock).toHaveBeenCalledTimes(1));
            expect(showSuccessMock).toHaveBeenCalledWith('Successfully approve salary request');
        });

        it('reports a refusal from the backend', async () => {
            approveMutateMock.mockRejectedValue(new Error('bad state'));
            render(<SalaryRequestDetailPage />);

            await userEvent.click(button('Approve'));

            await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not approve salary request'));
            expect(showSuccessMock).not.toHaveBeenCalled();
        });
    });

    describe('reject', () => {
        it('is available at the first stage with the reject permission', () => {
            render(<SalaryRequestDetailPage />);

            expect(button('Reject')).toBeEnabled();
        });

        it('is closed without the reject permission', () => {
            usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_REJECT: false });
            render(<SalaryRequestDetailPage />);

            expect(button('Reject')).toBeDisabled();
        });

        it.each(['wait_for_approval_accountant', 'wait_for_payment', 'paid', 'rejected'])(
            'is closed once the request is %s',
            (status) => {
                withDetail(detail({ status }));
                render(<SalaryRequestDetailPage />);

                expect(button('Reject')).toBeDisabled();
            },
        );

        it('opens the reject modal bound to this request', async () => {
            render(<SalaryRequestDetailPage />);

            await userEvent.click(button('Reject'));

            expect(screen.getByText('reject-modal:42')).toBeInTheDocument();
        });
    });

    describe('confirm paid', () => {
        it.each(['wait_for_approval', 'wait_for_approval_accountant', 'paid', 'rejected'])(
            'is closed while the request is %s',
            (status) => {
                withDetail(detail({ status }));
                render(<SalaryRequestDetailPage />);

                expect(button('Confirm paid')).toBeDisabled();
            },
        );

        it('opens once the accountant released the request for payment', () => {
            withDetail(detail({ status: 'wait_for_payment' }));
            render(<SalaryRequestDetailPage />);

            expect(button('Confirm paid')).toBeEnabled();
        });

        it('is closed without the confirm-paid permission', () => {
            withDetail(detail({ status: 'wait_for_payment' }));
            usePermissionsMock.mockReturnValue({ ...ALL_PERMISSIONS, SALARY_REQUEST_CONFIRM_PAID: false });
            render(<SalaryRequestDetailPage />);

            expect(button('Confirm paid')).toBeDisabled();
        });

        it('reports success', async () => {
            withDetail(detail({ status: 'wait_for_payment' }));
            render(<SalaryRequestDetailPage />);

            await userEvent.click(button('Confirm paid'));

            await waitFor(() => expect(confirmPaidMutateMock).toHaveBeenCalledTimes(1));
            expect(showSuccessMock).toHaveBeenCalledWith('Successfully confirm paid salary request');
        });

        it('reports a refusal from the backend', async () => {
            withDetail(detail({ status: 'wait_for_payment' }));
            confirmPaidMutateMock.mockRejectedValue(new Error('bad state'));
            render(<SalaryRequestDetailPage />);

            await userEvent.click(button('Confirm paid'));

            await waitFor(() =>
                expect(showErrorMock).toHaveBeenCalledWith('error|Could not confirm paid salary request'),
            );
        });
    });
});
