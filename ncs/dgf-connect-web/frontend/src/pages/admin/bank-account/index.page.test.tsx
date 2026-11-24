import React from 'react';
import { MantineProvider } from '@mantine/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DateHelper from '@/helpers/date.helper';
import IndexPage from './index.page';

DateHelper.initDayjs();

const { useGetBankAccountsQueryMock, usePermissionsMock, drawerSpy, routerMock } = vi.hoisted(() => ({
    useGetBankAccountsQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    drawerSpy: vi.fn(),
    routerMock: { query: { keyword: 'ALICE' }, push: vi.fn(), replace: vi.fn(), pathname: '/admin/bank-account' },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));
vi.mock('next/router', () => ({ useRouter: () => routerMock }));
vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? key.replace(/{{(\w+)}}/g, (_, k) => String(opts[k] ?? '')) : key,
    }),
}));
vi.mock('jotai', () => ({ useAtom: () => [{ workplaces: [{ id: 1, name: 'Shop A' }] }] }));
vi.mock('@/pages/global.state', () => ({ masterAtom: {} }));
vi.mock('@/hooks/usePermission', () => ({ usePermissions: (...args: unknown[]) => usePermissionsMock(...args) }));
vi.mock('@/pages/admin/bank-account/api', () => ({
    useGetBankAccountsQuery: (...args: unknown[]) => useGetBankAccountsQueryMock(...args),
}));
vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => <span>{`${user.name} (${user.staff_no})`}</span>,
}));
vi.mock('@/components/CommonSearchBox', () => ({ default: () => <div data-testid="search-box" /> }));
vi.mock('@/pages/admin/bank-account/partials/bank_account_detail_drawer', () => ({
    default: (props: any) => {
        drawerSpy(props);
        return <div data-testid="drawer">{props.bankAccountId ?? 'closed'}</div>;
    },
}));
vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) => {
        const records = Array.isArray(data) ? data : data?.data || [];
        return (
            <div>
                {records.map((row: any) => (
                    <div key={row.id} data-testid="row">
                        {columns.map((c: any) => (
                            <span key={c.key} data-testid={`col-${c.key}`}>
                                {c.render ? c.render(row) : row[c.key]}
                            </span>
                        ))}
                    </div>
                ))}
            </div>
        );
    },
}));

const row = {
    id: 7,
    user: {
        id: 3,
        name: 'NGUYEN VAN AN',
        staff_no: 'S-001',
        profile_picture: null,
        workplaces: [{ id: 1, name: 'Shop A' }],
    },
    payout_method: 'bank_transfer',
    status: 'wait_for_approval',
    bank_name: '三菱UFJ銀行',
    branch_name: '新宿支店',
    account_number_masked: '****567',
    account_holder_kana: 'グエン バン アン',
    name_check_verdict: 'mismatch',
    mismatch_count: 2,
    entered_manually: false,
    submitted_at: '2026-09-30 10:00:00',
    approved_at: null,
    rejected_note: null,
};

describe('Bank account IndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ BANK_ACCOUNT_LIST: true, BANK_ACCOUNT_APPROVE: true });
    });

    const renderPage = () =>
        render(
            <MantineProvider env="test">
                <IndexPage />
            </MantineProvider>,
        );

    it('passes the router query to the list query and renders check badges', () => {
        useGetBankAccountsQueryMock.mockReturnValue({
            data: { data: { data: [row], current_page: 1, last_page: 1, per_page: 15, total: 1 } },
            isLoading: false,
        });

        renderPage();

        expect(useGetBankAccountsQueryMock).toHaveBeenCalledWith({ keyword: 'ALICE' }, true);
        expect(screen.getByTestId('col-user').textContent).toContain('NGUYEN VAN AN');
        expect(screen.getByTestId('col-user').textContent).toContain('S-001');
        expect(screen.getByTestId('col-bank_name').textContent).toContain('****567');
        expect(screen.getByTestId('col-checks').textContent).toContain('Name does not match');
        expect(screen.getByTestId('col-checks').textContent).toContain('2');
        expect(screen.getByTestId('col-status').textContent).toContain('Waiting for approval');
    });

    it('opens the detail drawer for the selected row', () => {
        useGetBankAccountsQueryMock.mockReturnValue({
            data: { data: { data: [row], current_page: 1, last_page: 1, per_page: 15, total: 1 } },
            isLoading: false,
        });

        renderPage();
        expect(screen.getByTestId('drawer').textContent).toBe('closed');

        fireEvent.click(screen.getByRole('button', { name: 'View' }));

        expect(screen.getByTestId('drawer').textContent).toBe('7');
        expect(drawerSpy).toHaveBeenLastCalledWith(expect.objectContaining({ bankAccountId: 7, canDecide: true }));
    });

    it('does not show checks for a Seven Bank pickup row', () => {
        useGetBankAccountsQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        {
                            ...row,
                            payout_method: 'seven_bank_atm',
                            status: 'approved',
                            name_check_verdict: null,
                            mismatch_count: 0,
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 15,
                    total: 1,
                },
            },
            isLoading: false,
        });

        renderPage();

        expect(screen.getByTestId('col-checks').textContent).toBe('-');
        expect(screen.getByTestId('col-bank_name').textContent).toBe('-');
    });
});
