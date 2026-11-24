import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PaperOcrModal from './paper_ocr_modal';

const {
    useGetUserQueryMock,
    extractMock,
    applyMock,
    notificationsShowMock,
    buildCompareRowsMock,
    isPendingRef,
    isApplyPendingRef,
    permissionRef,
    getStaticUrlMock,
} = vi.hoisted(() => ({
    useGetUserQueryMock: vi.fn(),
    extractMock: vi.fn(),
    applyMock: vi.fn(),
    notificationsShowMock: vi.fn(),
    buildCompareRowsMock: vi.fn(),
    isPendingRef: { current: false },
    isApplyPendingRef: { current: false },
    permissionRef: { current: false },
    getStaticUrlMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
    // The OCR warning wording is shared through the real i18n instance, which initialises
    // itself against this module on import.
    initReactI18next: { type: '3rdParty', init: () => undefined },
}));

vi.mock('@mantine/core', () => {
    const Checkbox: any = ({ value, label, disabled, description, checked, onChange, 'aria-label': ariaLabel }: any) =>
        React.createElement(
            'label',
            null,
            React.createElement('input', {
                'type': 'checkbox',
                value,
                disabled,
                checked,
                'aria-label': ariaLabel ?? label,
                'onChange': (event: any) => {
                    if (onChange) {
                        onChange(event);
                        return;
                    }
                    (globalThis as any).__checkboxOnChange?.(value, event.target.checked);
                },
            }),
            label,
            description ? React.createElement('span', null, description) : null,
        );
    Checkbox.Group = ({ children, value, onChange }: any) => {
        (globalThis as any).__checkboxOnChange = (val: string, checked: boolean) =>
            onChange(checked ? [...value, val] : value.filter((v: string) => v !== val));
        return React.createElement('div', null, children);
    };

    const Tabs: any = ({ children }: any) => React.createElement('div', null, children);
    Tabs.List = ({ children }: any) => React.createElement('div', null, children);
    Tabs.Tab = ({ children, value }: any) => React.createElement('button', { type: 'button', role: 'tab' }, children);
    // keepMounted={false} in the real component renders only the active panel; the tests
    // assert on content, so rendering every panel is close enough and avoids tab plumbing.
    Tabs.Panel = ({ children }: any) => React.createElement('div', null, children);

    const Table: any = ({ children }: any) => React.createElement('table', null, children);
    Table.Thead = ({ children }: any) => React.createElement('thead', null, children);
    Table.Tbody = ({ children }: any) => React.createElement('tbody', null, children);
    Table.Tr = ({ children }: any) => React.createElement('tr', null, children);
    Table.Th = ({ children }: any) => React.createElement('th', null, children);
    Table.Td = ({ children }: any) => React.createElement('td', null, children);

    return {
        Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
        Badge: ({ children, color }: any) =>
            React.createElement('span', { 'data-testid': 'badge', 'data-color': color }, children),
        Button: ({ children, onClick, disabled, loading }: any) =>
            React.createElement('button', { 'type': 'button', onClick, disabled, 'data-loading': !!loading }, children),
        Checkbox,
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Image: ({ src, alt }: any) => React.createElement('img', { src, alt }),
        Modal: ({ children, opened, title }: any) =>
            opened ? React.createElement('div', { 'data-testid': 'modal', 'data-title': title }, children) : null,
        Stack: ({ children }: any) => React.createElement('div', null, children),
        Table,
        Tabs,
        Text: ({ children }: any) => React.createElement('span', null, children),
    };
});

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('react-icons/lu', () => ({
    LuArrowLeft: () => React.createElement('span', null, '<'),
    LuCheck: () => React.createElement('span', null, '✓'),
    LuRefreshCw: () => React.createElement('span', null, '↻'),
    LuScanText: () => React.createElement('span', null, '⌗'),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string) => `date:${value}` },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (enumObj: Record<string, { value: string; text: string }>, value: string) =>
            Object.values(enumObj).find((entry) => entry.value === value)?.text ?? '',
    },
}));

vi.mock('@/helpers/url.helper', () => ({
    default: { getStaticUrl: (...args: unknown[]) => getStaticUrlMock(...args) },
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children }: any) => React.createElement('div', { 'data-testid': 'alert' }, children),
}));

vi.mock('@/pages/admin/user/partials/paper_ocr_compare', () => ({
    buildCompareRows: (...args: unknown[]) => buildCompareRowsMock(...args),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: () => ({ USER_PAPER_APPROVE: permissionRef.current }),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserQuery: (...args: unknown[]) => useGetUserQueryMock(...args),
    useExtractUserPapersOcrMutation: () => ({ mutateAsync: extractMock, isPending: isPendingRef.current }),
    useApplyUserPapersOcrMutation: () => ({ mutateAsync: applyMock, isPending: isApplyPendingRef.current }),
}));

const paperItems = [
    { id: 1, type: 'passport', folder: 'current', approved_at: '2026-01-01', rejected_at: null, file_path: 'p1.png' },
    {
        id: 2,
        type: 'foreigner_card_front',
        folder: 'current',
        approved_at: '2026-01-01',
        rejected_at: null,
        file_path: 'f1.png',
    },
    // Pending images are usable; rejected and archived images are not.
    { id: 3, type: 'student_card_front', folder: 'current', approved_at: '2026-01-01', rejected_at: '2026-01-02' },
    { id: 4, type: 'student_card_back', folder: 'archive', approved_at: '2026-01-01', rejected_at: null },
    { id: 5, type: 'foreigner_card_back', folder: 'current', approved_at: null, rejected_at: null },
] as any;

const onClose = vi.fn();

const renderModal = (items = paperItems) =>
    render(<PaperOcrModal opened={true} onClose={onClose} userId="42" paperItems={items} />);

const selectPassport = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getByRole('checkbox', { name: 'Passport' }));
};

describe('PaperOcrModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        isPendingRef.current = false;
        isApplyPendingRef.current = false;
        permissionRef.current = false;
        applyMock.mockResolvedValue(undefined);
        useGetUserQueryMock.mockReturnValue({ data: { data: { first_name: 'JOHN', last_name: 'DOE', info: {} } } });
        buildCompareRowsMock.mockReturnValue([]);
        getStaticUrlMock.mockImplementation((path: string) => `https://cdn.test/${path}`);
    });

    it('renders nothing when closed', () => {
        render(<PaperOcrModal opened={false} onClose={onClose} userId="42" paperItems={paperItems} />);

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('opens on the select step', () => {
        renderModal();

        expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Read from image');
    });

    it('enables types that have a usable approved or pending image', () => {
        renderModal();

        expect(screen.getByRole('checkbox', { name: 'Passport' })).toBeEnabled();
        expect(screen.getByRole('checkbox', { name: 'Foreigner Card Front' })).toBeEnabled();
        expect(screen.getByRole('checkbox', { name: 'Foreigner Card Back' })).toBeEnabled();
        expect(screen.getByRole('checkbox', { name: 'Student Card Front' })).toBeDisabled();
        expect(screen.getByRole('checkbox', { name: 'Student Card Back' })).toBeDisabled();
        expect(screen.getByRole('checkbox', { name: 'Foreigner Card Scan' })).toBeDisabled();
    });

    it('explains why a type is unavailable', () => {
        renderModal();

        expect(screen.getAllByText('No usable image')).toHaveLength(3);
    });

    it('disables the submit button until a type is selected', async () => {
        const user = userEvent.setup();
        renderModal();

        const submit = screen.getAllByRole('button', { name: /Read from image/ })[0];
        expect(submit).toBeDisabled();

        await selectPassport(user);
        expect(screen.getAllByRole('button', { name: /Read from image/ })[0]).toBeEnabled();
    });

    it('shows a hint and marks the button loading while extraction is pending', () => {
        isPendingRef.current = true;
        renderModal();

        expect(screen.getByText('Reading document images, this may take up to a minute')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /Read from image/ })[0]).toHaveAttribute('data-loading', 'true');
    });

    it('cancel resets and calls onClose', async () => {
        const user = userEvent.setup();
        renderModal();

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('submits the selected types and moves to the result step', async () => {
        const user = userEvent.setup();
        extractMock.mockResolvedValue({
            data: { results: [{ group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] }] },
        });
        renderModal();

        await selectPassport(user);
        await user.click(screen.getAllByRole('button', { name: /Read from image/ })[0]);

        await waitFor(() => expect(extractMock).toHaveBeenCalledWith({ types: ['passport'], force: false }));
        expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Extracted information');
    });

    it('shows an error notification and stays on the select step when extraction fails', async () => {
        const user = userEvent.setup();
        extractMock.mockRejectedValue(new Error('boom'));
        renderModal();

        await selectPassport(user);
        await user.click(screen.getAllByRole('button', { name: /Read from image/ })[0]);

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not read from image' }),
            ),
        );
        expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Read from image');
    });

    const goToResult = async (results: unknown[]) => {
        const user = userEvent.setup();
        extractMock.mockResolvedValue({ data: { results } });
        renderModal();
        await selectPassport(user);
        await user.click(screen.getAllByRole('button', { name: /Read from image/ })[0]);
        await waitFor(() => expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Extracted information'));
        return user;
    };

    it('shows an alert for a failed group instead of the comparison table', async () => {
        await goToResult([{ group: 'passport', types: ['passport'], status: 'failed', fields: null, warnings: [] }]);

        expect(screen.getByTestId('alert')).toHaveTextContent('error|Could not read this document');
    });

    it('renders the source images of the group with a link to the full size file', async () => {
        await goToResult([{ group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] }]);

        expect(screen.getByAltText('Passport')).toHaveAttribute('src', 'https://cdn.test/p1.png');
        expect(screen.getByRole('link')).toHaveAttribute('href', 'https://cdn.test/p1.png');
    });

    it('renders compare rows with translated field labels and status badges', async () => {
        buildCompareRowsMock.mockReturnValue([
            { field: 'birthday', extracted: '2000-01-02', current: '2000-01-02', status: 'match' },
            { field: 'nationality', extracted: null, current: null, status: 'no_data' },
        ]);

        await goToResult([{ group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] }]);

        expect(screen.getByText('Birthday')).toBeInTheDocument();
        expect(screen.getByText('Nationality')).toBeInTheDocument();
        expect(screen.getAllByText('date:2000-01-02')).toHaveLength(2);
        const badges = screen.getAllByTestId('badge');
        expect(badges[0]).toHaveAttribute('data-color', 'teal');
        expect(badges[1]).toHaveAttribute('data-color', 'gray');
    });

    it('passes the current user values into the comparison', async () => {
        await goToResult([{ group: 'passport', types: ['passport'], status: 'ok', fields: { a: '1' }, warnings: [] }]);

        expect(buildCompareRowsMock).toHaveBeenCalledWith(
            'passport',
            { a: '1' },
            { last_name: 'DOE', first_name: 'JOHN', last_name_kana: null, first_name_kana: null, info: {} },
        );
    });

    it('falls back to the raw residence status text when the value could not be mapped', async () => {
        buildCompareRowsMock.mockReturnValue([
            { field: 'residence_status', extracted: null, current: null, status: 'not_read' },
        ]);

        await goToResult([
            {
                group: 'residence_card',
                types: ['foreigner_card_front'],
                status: 'ok',
                fields: { residence_status_raw: '留学' },
                warnings: [],
            },
        ]);

        expect(screen.getByText('- (留学)')).toBeInTheDocument();
        expect(screen.getByTestId('badge')).toHaveAttribute('data-color', 'yellow');
    });

    it('renders a dash for a missing current value', async () => {
        buildCompareRowsMock.mockReturnValue([
            { field: 'passport_number', extracted: 'X1', current: null, status: 'mismatch' },
        ]);

        await goToResult([{ group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] }]);

        expect(screen.getByText('X1')).toBeInTheDocument();
        expect(screen.getByText('-')).toBeInTheDocument();
        expect(screen.getByTestId('badge')).toHaveAttribute('data-color', 'red');
    });

    it('translates known warnings and passes unknown ones through', async () => {
        await goToResult([
            {
                group: 'passport',
                types: ['passport'],
                status: 'ok',
                fields: {},
                warnings: ['residence_number_format', 'birthday_unparseable_date', 'some_unknown_warning'],
            },
        ]);

        expect(screen.getByText('Residence card number format looks invalid')).toBeInTheDocument();
        expect(screen.getByText('A date on the document could not be read reliably')).toBeInTheDocument();
        expect(screen.getByText('some_unknown_warning')).toBeInTheDocument();
    });

    it('back returns to the select step keeping the modal open', async () => {
        const user = await goToResult([
            { group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] },
        ]);

        await user.click(screen.getByRole('button', { name: /Back/ }));

        expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Read from image');
        expect(onClose).not.toHaveBeenCalled();
    });

    it('re-runs extraction with the force flag', async () => {
        const user = await goToResult([
            { group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] },
        ]);
        extractMock.mockClear();

        await user.click(screen.getByRole('button', { name: 'Re-run' }));

        await waitFor(() => expect(extractMock).toHaveBeenCalledWith({ types: ['passport'], force: true }));
    });

    it('applies preselected mismatched fields to the user profile', async () => {
        permissionRef.current = true;
        buildCompareRowsMock.mockReturnValue([
            { field: 'passport_number', extracted: 'X123', current: 'X999', status: 'mismatch' },
        ]);
        const user = await goToResult([
            {
                group: 'passport',
                types: ['passport'],
                status: 'ok',
                fields: { passport_number: 'X123' },
                warnings: [],
                ocr_result_id: 7,
                from_cache: false,
                cached_at: null,
            },
        ]);

        expect(screen.getByRole('checkbox', { name: 'Apply' })).toBeChecked();
        await user.click(screen.getByRole('button', { name: /Apply selected/ }));

        await waitFor(() => expect(applyMock).toHaveBeenCalledWith({ ocr_result_id: 7, fields: ['passport_number'] }));
        expect(notificationsShowMock).toHaveBeenCalledWith({
            color: 'teal',
            message: 'The selected values were applied to the profile',
        });
    });

    it('close on the result step resets back to the select step', async () => {
        const user = await goToResult([
            { group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] },
        ]);

        await user.click(screen.getByRole('button', { name: 'Close' }));

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(screen.getByTestId('modal')).toHaveAttribute('data-title', 'Read from image');
    });
});
