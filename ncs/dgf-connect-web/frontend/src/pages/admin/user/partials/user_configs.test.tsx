import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserConfigs from './user_configs';

const { useGetUserConfigsQueryMock, updateUserConfigMock, usePermissionsMock, notificationsShowMock } = vi.hoisted(
    () => ({
        useGetUserConfigsQueryMock: vi.fn(),
        updateUserConfigMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        notificationsShowMock: vi.fn(),
    }),
);

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [value, setValue] = React.useState(initial);
        return [value, { open: () => setValue(true), close: () => setValue(false) }];
    },
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues }: any) => {
        const values = { ...initialValues };
        return {
            values,
            onSubmit: (fn: any) => (e: any) => {
                e?.preventDefault?.();
                return fn(values);
            },
            getInputProps: (_: string) => ({}),
            reset: vi.fn(),
            setValues: (v: any) => Object.assign(values, v),
            resetDirty: vi.fn(),
        };
    },
}));

vi.mock('@mantine/core', () => {
    const Grid = ({ children }: any) => React.createElement('div', null, children);
    Grid.Col = ({ children }: any) => React.createElement('div', null, children);

    return {
        Button: ({ children, type, onClick, disabled, loading }: any) =>
            React.createElement('button', { type: type ?? 'button', onClick, disabled: disabled || loading }, children),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Grid,
        Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
        Modal: ({ children, opened, title }: any) =>
            opened ? React.createElement('div', { 'data-testid': 'modal' }, children) : null,
        NumberInput: ({ label }: any) => React.createElement('input', { 'type': 'number', 'aria-label': label }),
        Title: ({ children }: any) => React.createElement('h3', null, children),
    };
});

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label }: any) => React.createElement('input', { 'aria-label': label }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'isSelect'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { string: () => chain, number: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (d: string) => d ?? '' },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: {
        toParams: (v: any) => v,
        setFormError: vi.fn(),
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                (data ?? []).map((row: any, ri: number) =>
                    React.createElement(
                        'tr',
                        { key: ri },
                        (columns ?? []).map((col: any, ci: number) =>
                            React.createElement('td', { key: ci }, col.render ? col.render(row) : row[col.key]),
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserConfigsQuery: (...args: unknown[]) => useGetUserConfigsQueryMock(...args),
    useUpdateUserConfigsMutation: () => ({ mutateAsync: updateUserConfigMock }),
}));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => null,
    LuPencilLine: () => null,
    LuSave: () => null,
}));

const fakeConfigs = {
    max_shift_per_day: '3',
    max_hour_per_week: '40',
    max_shift_per_day_start_date: '2024-01-01',
    max_shift_per_day_expiry_date: null,
    max_hour_per_week_start_date: '2024-01-01',
    max_hour_per_week_expiry_date: null,
};

describe('UserConfigs', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ USER_CONFIG_LIST: true, USER_CONFIG_EDIT: true });
        useGetUserConfigsQueryMock.mockReturnValue({ data: { data: fakeConfigs }, isPending: false });
    });

    it('shows loader while fetching', () => {
        useGetUserConfigsQueryMock.mockReturnValue({ data: undefined, isPending: true });

        render(<UserConfigs id="42" />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('renders Configuration title', () => {
        render(<UserConfigs id="42" />);

        expect(screen.getByText('Configuration')).toBeInTheDocument();
    });

    it('renders config rows in the table', () => {
        render(<UserConfigs id="42" />);

        expect(screen.getByText('Max shifts per day')).toBeInTheDocument();
        expect(screen.getByText('Max hours per week')).toBeInTheDocument();
    });

    it('Edit button is disabled without USER_CONFIG_EDIT permission', () => {
        usePermissionsMock.mockReturnValue({ USER_CONFIG_LIST: true, USER_CONFIG_EDIT: false });

        render(<UserConfigs id="42" />);

        expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
    });

    it('clicking Edit opens modal', async () => {
        const user = userEvent.setup();

        render(<UserConfigs id="42" />);

        await user.click(screen.getByRole('button', { name: 'Edit' }));

        expect(screen.getByTestId('modal')).toBeInTheDocument();
    });

    it('submit in modal calls updateUserConfig and shows success notification', async () => {
        const user = userEvent.setup();
        updateUserConfigMock.mockResolvedValue({});

        render(<UserConfigs id="42" />);

        await user.click(screen.getByRole('button', { name: 'Edit' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(updateUserConfigMock).toHaveBeenCalled();
        });
        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({ color: 'teal', message: 'Successfully updated configuration' }),
        );
    });
});
