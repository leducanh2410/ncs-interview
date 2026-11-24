import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DateHelper from '@/helpers/date.helper';
import BankAccountDetailDrawer from './bank_account_detail_drawer';

DateHelper.initDayjs();

const { detailQueryMock, approveMock, rejectMock } = vi.hoisted(() => ({
    detailQueryMock: vi.fn(),
    approveMock: vi.fn(),
    rejectMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) =>
            opts ? key.replace(/{{(\w+)}}/g, (_, k) => String(opts[k] ?? '')) : key,
    }),
}));
vi.mock('@/locales/i18n', () => ({ default: { t: (key: string) => key } }));
vi.mock('@/pages/admin/bank-account/api', () => ({
    useGetBankAccountDetailQuery: (...args: unknown[]) => detailQueryMock(...args),
    useApproveBankAccountMutation: () => ({ mutateAsync: approveMock, isPending: false }),
    useRejectBankAccountMutation: () => ({ mutateAsync: rejectMock, isPending: false }),
}));
vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => <span>{`${user.name} (${user.staff_no})`}</span>,
}));
vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: vi.fn(), showError: vi.fn() },
}));

const account = {
    id: 7,
    user_id: 3,
    user: {
        id: 3,
        name: 'NGUYEN VAN AN',
        name_kana: 'グエン バン アン',
        staff_no: 'S-001',
        workplaces: [{ id: 1, name: 'Shop A' }],
    },
    approver: null,
    history: [],
    payout_method: 'bank_transfer',
    status: 'wait_for_approval',
    bank_name: '三菱UFJ銀行',
    bank_code: '0005',
    branch_name: '新宿支店',
    branch_code: '021',
    account_type: 'ordinary',
    account_number_masked: '****321',
    account_number: '7654321',
    account_holder_kana: 'タナカ タロウ',
    account_holder_latin: null,
    is_yucho: false,
    yucho_symbol: null,
    yucho_number: null,
    ocr_result_id: 9,
    ocr_run_count: 1,
    ocr_max_runs: 3,
    can_reupload: false,
    ocr_fields: {
        bank_name: '三菱UFJ銀行',
        bank_code: '0005',
        branch_name: '新宿支店',
        branch_code: '021',
        account_type: 'ordinary',
        account_number: '1234567',
        holder_name_kana: 'グエン バン アン',
        holder_name_latin: null,
        is_yucho: false,
        yucho_symbol: null,
        yucho_number: null,
    },
    ocr_warnings: [],
    ocr_error: null,
    confirmed_fields: {
        bank_name: '三菱UFJ銀行',
        bank_code: '0005',
        branch_name: '新宿支店',
        branch_code: '021',
        account_number: '7654321',
        account_holder_kana: 'タナカ タロウ',
        account_holder_latin: null,
        is_yucho: false,
        yucho_symbol: null,
        yucho_number: null,
    },
    entered_manually: false,
    name_check: {
        verdict: 'mismatch',
        kana: { registered: 'グエン バン アン', holder: 'タナカ タロウ', verdict: 'mismatch' },
        latin: null,
        warnings: [],
        ai: { available: true, verdict: 'different_person', confidence: 0.9, reason: 'Family names differ' },
    },
    input_mismatches: [
        { field: 'account_number', ocr_value: '1234567', input_value: '7654321' },
        { field: 'account_holder_kana', ocr_value: 'グエン バン アン', input_value: 'タナカ タロウ' },
    ],
    approver_id: null,
    submitted_at: '2026-09-30 10:00:00',
    approved_at: null,
    rejected_note: null,
    superseded_at: null,
    images: [{ id: 1, file_path: 'users/3/bank/card.jpg', folder: 'current', url: 'https://cdn/card.jpg' }],
    images_restricted: false,
    created_at: null,
    updated_at: null,
};

const renderDrawer = (props: Partial<React.ComponentProps<typeof BankAccountDetailDrawer>> = {}) =>
    render(
        <MantineProvider env="test">
            <BankAccountDetailDrawer bankAccountId={7} canDecide canView onClose={vi.fn()} {...props} />
        </MantineProvider>,
    );

describe('BankAccountDetailDrawer', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        detailQueryMock.mockReturnValue({ data: { data: account }, isLoading: false });
        approveMock.mockResolvedValue(undefined);
        rejectMock.mockResolvedValue(undefined);
    });

    it('shows the comparison with mismatched rows and the AI opinion', () => {
        renderDrawer();

        expect(screen.getByTestId('compare-account_number').textContent).toContain('1234567');
        expect(screen.getByTestId('compare-account_number').textContent).toContain('7654321');
        expect(screen.getByTestId('compare-bank_name').textContent).toContain('三菱UFJ銀行');
        expect(screen.getByText('enum|Name does not match')).toBeInTheDocument();
        expect(screen.getByText(/AI opinion: different_person \(90%\) - Family names differ/)).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Card photo' })).toHaveAttribute('src', 'https://cdn/card.jpg');
    });

    it('approves the record', async () => {
        renderDrawer();

        await userEvent.click(screen.getByRole('button', { name: /Approve/ }));

        await waitFor(() => expect(approveMock).toHaveBeenCalledWith(7));
    });

    it('requires a note before rejecting and sends it', async () => {
        renderDrawer();

        await userEvent.click(screen.getByRole('button', { name: /^Reject$/ }));
        const buttons = await screen.findAllByRole('button', { name: /^Reject$/ });
        await userEvent.click(buttons[buttons.length - 1]);
        expect(await screen.findByText('validation|Please enter a reason')).toBeInTheDocument();
        expect(rejectMock).not.toHaveBeenCalled();

        await userEvent.type(screen.getByLabelText(/^Reason/), '口座名義が一致しません');
        const confirmButtons = screen.getAllByRole('button', { name: /^Reject$/ });
        await userEvent.click(confirmButtons[confirmButtons.length - 1]);

        await waitFor(() => expect(rejectMock).toHaveBeenCalledWith({ id: 7, note: '口座名義が一致しません' }));
    });

    it('hides the decision buttons without the approve permission or outside the waiting state', () => {
        renderDrawer({ canDecide: false });
        expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument();

        detailQueryMock.mockReturnValue({ data: { data: { ...account, status: 'approved' } }, isLoading: false });
        renderDrawer();
        expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument();
    });

    it('replaces the card photos with a notice when the viewer cannot see them', () => {
        detailQueryMock.mockReturnValue({
            data: { data: { ...account, images: [], images_restricted: true } },
            isLoading: false,
        });

        renderDrawer({ canDecide: false });

        expect(screen.queryByRole('img', { name: 'Card photo' })).not.toBeInTheDocument();
        expect(
            screen.getByText('Card photos are only shown to admins who can approve bank accounts.'),
        ).toBeInTheDocument();
    });

    it('explains a Seven Bank pickup instead of showing bank details', () => {
        detailQueryMock.mockReturnValue({
            data: {
                data: {
                    ...account,
                    payout_method: 'seven_bank_atm',
                    status: 'approved',
                    name_check: null,
                    input_mismatches: [],
                },
            },
            isLoading: false,
        });

        renderDrawer();

        expect(screen.getByText(/receives salary in cash at a Seven Bank ATM/)).toBeInTheDocument();
        expect(screen.queryByTestId('compare-account_number')).not.toBeInTheDocument();
    });
});
