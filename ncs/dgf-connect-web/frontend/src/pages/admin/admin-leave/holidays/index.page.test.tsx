import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/admin-leave/holidays/index.page';

const {
    dataTableSpy,
    createUpdateModalSpy,
    useGetCompanyHolidaysQueryMock,
    useGenerateCompanyHolidaysMutationMock,
    useDeleteCompanyHolidayMutationMock,
    useUpdateCompanyHolidaySettingMutationMock,
    usePermissionsMock,
    notificationsMock,
    routerMock,
} = vi.hoisted(() => ({
    dataTableSpy: vi.fn(),
    createUpdateModalSpy: vi.fn(),
    useGetCompanyHolidaysQueryMock: vi.fn(),
    useGenerateCompanyHolidaysMutationMock: vi.fn(),
    useDeleteCompanyHolidayMutationMock: vi.fn(),
    useUpdateCompanyHolidaySettingMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    routerMock: { query: {} as Record<string, unknown>, push: vi.fn() },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
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

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, loading, disabled }: any) =>
        React.createElement('button', { onClick, disabled: disabled || loading }, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    NumberInput: ({ label, value, onChange, onBlur, onKeyDown }: any) =>
        React.createElement('input', {
            'type': 'number',
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange?.(e.target.value === '' ? '' : Number(e.target.value)),
            'onBlur': onBlur,
            'onKeyDown': onKeyDown,
        }),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ checked, onChange, label, disabled }: any) =>
        React.createElement(
            'label',
            null,
            React.createElement('input', {
                type: 'checkbox',
                role: 'switch',
                checked: checked ?? false,
                onChange,
                disabled,
            }),
            label,
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => ({ year: () => 2026 }),
        parseAndFormat: (value: string | null) => (value ? `formatted:${value}` : '-'),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_e: any, value: string) => value,
        getColor: () => 'gray',
    },
}));

vi.mock('@/pages/admin/admin-leave/holidays/api', () => ({
    useGetCompanyHolidaysQuery: (...args: unknown[]) => useGetCompanyHolidaysQueryMock(...args),
    useGenerateCompanyHolidaysMutation: () => useGenerateCompanyHolidaysMutationMock(),
    useDeleteCompanyHolidayMutation: () => useDeleteCompanyHolidayMutationMock(),
    useUpdateCompanyHolidaySettingMutation: () => useUpdateCompanyHolidaySettingMutationMock(),
}));

vi.mock('@/pages/admin/admin-leave/holidays/partials/holiday_create_update_modal', () => ({
    default: (props: any) => {
        createUpdateModalSpy(props);
        return props.opened
            ? React.createElement('div', { 'data-testid': 'create-update-modal' }, props.holiday?.name ?? 'new')
            : null;
    },
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'confirm-delete' }, 'Delete'),
                  React.createElement('button', { 'onClick': onCancel, 'data-testid': 'cancel-delete' }, 'Cancel'),
              )
            : null,
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            { 'data-no-permission': props.noPermission ? 'true' : 'false' },
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

const makeHoliday = (overrides: Record<string, any> = {}) => ({
    id: 1,
    date: '2026-01-01',
    name: '元日',
    source: 'library',
    ...overrides,
});

describe('Company holidays page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({ ADMIN_LEAVE_MANAGE: true });
        useGetCompanyHolidaysQueryMock.mockReturnValue({
            data: { data: { year: 2026, exclude_weekends: false, holidays: [makeHoliday()] } },
            isPending: false,
        });
        useGenerateCompanyHolidaysMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue({ data: { inserted: 16 } }),
            isPending: false,
        });
        useDeleteCompanyHolidayMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateCompanyHolidaySettingMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('defaults the year to the current year when the URL carries none', () => {
        render(<IndexPage />);

        expect(useGetCompanyHolidaysQueryMock).toHaveBeenCalledWith(2026, true);
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(2026);
    });

    it('reads the year from the URL query', () => {
        routerMock.query = { year: '2027' };

        render(<IndexPage />);

        expect(useGetCompanyHolidaysQueryMock).toHaveBeenCalledWith(2027, true);
    });

    it('does not push to the router on every keystroke, only once the year is committed on blur', () => {
        render(<IndexPage />);

        const input = screen.getByRole('spinbutton', { name: 'Year' });
        fireEvent.change(input, { target: { value: '2' } });
        fireEvent.change(input, { target: { value: '20' } });
        expect(routerMock.push).not.toHaveBeenCalled();

        fireEvent.change(input, { target: { value: '2027' } });
        fireEvent.blur(input);

        expect(routerMock.push).toHaveBeenCalledWith({ query: { year: 2027 } });
    });

    it('commits the year on Enter as well as on blur', () => {
        render(<IndexPage />);

        const input = screen.getByRole('spinbutton', { name: 'Year' });
        fireEvent.change(input, { target: { value: '2027' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(routerMock.push).toHaveBeenCalledWith({ query: { year: 2027 } });
    });

    it('reverts to the current year instead of navigating when the committed value is out of range', () => {
        render(<IndexPage />);

        const input = screen.getByRole('spinbutton', { name: 'Year' });
        fireEvent.change(input, { target: { value: '' } });
        fireEvent.blur(input);

        expect(routerMock.push).not.toHaveBeenCalled();
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(2026);
    });

    it('generates for the just-typed year even when clicked before the router navigation resolves', async () => {
        const mutateAsync = vi.fn().mockResolvedValue({ data: { inserted: 16 } });
        useGenerateCompanyHolidaysMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        const input = screen.getByRole('spinbutton', { name: 'Year' });
        fireEvent.change(input, { target: { value: '2027' } });
        // Blur commits the year and calls router.push, but router.push is async in real Next.js —
        // routerMock.query is deliberately left unchanged here to simulate the navigation not
        // having landed yet by the time the click below fires.
        fireEvent.blur(input);
        expect(routerMock.push).toHaveBeenCalledWith({ query: { year: 2027 } });

        fireEvent.click(screen.getByText('Generate from Japanese holiday calendar'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(2027);
        });
    });

    it('toggling weekend exclusion uses the just-typed year even before the router navigation resolves', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateCompanyHolidaySettingMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        const input = screen.getByRole('spinbutton', { name: 'Year' });
        fireEvent.change(input, { target: { value: '2027' } });
        fireEvent.blur(input);

        fireEvent.click(screen.getByRole('switch'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ year: 2027, exclude_weekends: true });
        });
    });

    it('renders the holiday table with a source badge and always offers Generate', () => {
        render(<IndexPage />);

        expect(screen.getByText('元日')).toBeInTheDocument();
        expect(screen.getByText('library')).toBeInTheDocument();
        expect(screen.getByText('Generate from Japanese holiday calendar')).toBeInTheDocument();
    });

    it('shows an empty state and still offers generation when the year has no holidays', () => {
        useGetCompanyHolidaysQueryMock.mockReturnValue({
            data: { data: { year: 2026, exclude_weekends: false, holidays: [] } },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByText('No holidays for 2026 yet.')).toBeInTheDocument();
        expect(screen.getByText('Generate from Japanese holiday calendar')).toBeInTheDocument();
    });

    it('generating calls the generate mutation with the current year and reports the inserted count', async () => {
        useGetCompanyHolidaysQueryMock.mockReturnValue({
            data: { data: { year: 2026, exclude_weekends: false, holidays: [] } },
            isPending: false,
        });
        const mutateAsync = vi.fn().mockResolvedValue({ data: { inserted: 16 } });
        useGenerateCompanyHolidaysMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Generate from Japanese holiday calendar'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(2026);
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Generated 16 holidays' }),
            );
        });
    });

    it('the weekend switch reflects the fetched setting and toggling it calls the settings mutation', async () => {
        useGetCompanyHolidaysQueryMock.mockReturnValue({
            data: { data: { year: 2026, exclude_weekends: true, holidays: [makeHoliday()] } },
            isPending: false,
        });
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateCompanyHolidaySettingMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        const toggle = screen.getByRole('switch') as HTMLInputElement;
        expect(toggle.checked).toBe(true);

        fireEvent.click(toggle);

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ year: 2026, exclude_weekends: false });
        });
    });

    it('clicking Add holiday opens the create/update modal with no holiday selected', () => {
        render(<IndexPage />);

        fireEvent.click(screen.getByText('Add holiday'));

        expect(createUpdateModalSpy).toHaveBeenCalledWith(expect.objectContaining({ holiday: null, opened: true }));
    });

    it('clicking the edit action opens the create/update modal with the selected holiday', () => {
        render(<IndexPage />);

        // Buttons in DOM order: "Generate", "Add holiday", then the row's edit icon, then its delete icon.
        fireEvent.click(screen.getAllByRole('button')[2]);

        expect(createUpdateModalSpy).toHaveBeenCalledWith(
            expect.objectContaining({ holiday: expect.objectContaining({ id: 1 }), opened: true }),
        );
    });

    it('deleting a holiday opens a confirmation and calls the delete mutation on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteCompanyHolidayMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        const buttons = screen.getAllByRole('button');
        fireEvent.click(buttons[3]);

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('confirm-delete'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(1);
        });
    });

    it('passes noPermission true to the data table when the permission is absent', () => {
        usePermissionsMock.mockReturnValue({ ADMIN_LEAVE_MANAGE: false });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });
});
