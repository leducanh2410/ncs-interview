import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import HolidayCreateUpdateModal from './holiday_create_update_modal';

const { useCreateCompanyHolidayMutationMock, useUpdateCompanyHolidayMutationMock, notificationsMock } = vi.hoisted(
    () => ({
        useCreateCompanyHolidayMutationMock: vi.fn(),
        useUpdateCompanyHolidayMutationMock: vi.fn(),
        notificationsMock: { show: vi.fn() },
    }),
);

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, 'save-icon'),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, type, loading }: any) =>
        React.createElement('button', { onClick, type: type || 'button', disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    TextInput: ({ label, value, onChange, error }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'input',
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            }),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, value, onChange }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'value': value ? String(value) : '',
            'onChange': (e: any) => onChange?.(e.target.value ? new Date(e.target.value) : null),
        }),
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValues] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            setValues: (v: any) => setValues((prev: any) => ({ ...prev, ...v })),
            resetDirty: vi.fn(),
            reset: () => {
                setValues(initialValues);
                setErrors({});
            },
            getInputProps: (field: string) => ({
                value: values[field] ?? '',
                onChange: (v: any) => setValues((prev: any) => ({ ...prev, [field]: v })),
                error: errors[field],
            }),
            onSubmit: (handler: (v: any) => void) => (e: any) => {
                e.preventDefault();
                const validationErrors: Record<string, string> = {};
                for (const field of Object.keys(validate)) {
                    const message = validate[field](values[field], values, field);
                    if (message) {
                        validationErrors[field] = message;
                    }
                }
                if (Object.keys(validationErrors).length > 0) {
                    setErrors(validationErrors);
                    return;
                }
                handler(values);
            },
        };
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parse: (value: string) => ({ toDate: () => new Date(`${value}T00:00:00Z`) }),
        format: (_value: Date, _fmt: string) => '2027-01-05',
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
}));

vi.mock('@/pages/admin/admin-leave/holidays/api', () => ({
    useCreateCompanyHolidayMutation: () => useCreateCompanyHolidayMutationMock(),
    useUpdateCompanyHolidayMutation: () => useUpdateCompanyHolidayMutationMock(),
}));

const makeHoliday = (overrides: Record<string, any> = {}) =>
    ({
        id: 5,
        date: '2026-01-01',
        name: '元日',
        source: 'library',
        ...overrides,
    }) as any;

function renderComponent(props: Partial<React.ComponentProps<typeof HolidayCreateUpdateModal>> = {}) {
    const defaults = { holiday: null, opened: true, onClose: vi.fn() };
    return { onClose: defaults.onClose, ...render(<HolidayCreateUpdateModal {...defaults} {...props} />) };
}

describe('HolidayCreateUpdateModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useCreateCompanyHolidayMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateCompanyHolidayMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('renders nothing when closed', () => {
        const { container } = renderComponent({ opened: false });

        expect(container).toBeEmptyDOMElement();
    });

    it('shows "Add holiday" title when no holiday is passed', () => {
        renderComponent({ holiday: null });

        expect(screen.getByText('Add holiday')).toBeInTheDocument();
    });

    it('shows "Edit holiday" title and pre-fills the name when a holiday is passed', () => {
        renderComponent({ holiday: makeHoliday({ name: '振替休日' }) });

        expect(screen.getByText('Edit holiday')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Name' })).toHaveValue('振替休日');
    });

    it('blocks submission and does not call the mutation when required fields are empty', async () => {
        const mutateAsync = vi.fn();
        useCreateCompanyHolidayMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ holiday: null });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByRole('alert').length).toBeGreaterThan(0);
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('calls updateMutation with the holiday id when editing', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateCompanyHolidayMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ holiday: makeHoliday({ id: 7, name: '振替休日' }) });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({ id: 7, data: expect.objectContaining({ name: '振替休日' }) }),
            );
        });
    });

    it('calls onClose when Cancel is clicked without submitting', async () => {
        const mutateAsync = vi.fn();
        useCreateCompanyHolidayMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();
        renderComponent({ onClose });

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalled();
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('disables the Save button while a mutation is pending', () => {
        useCreateCompanyHolidayMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });

        renderComponent({ holiday: null });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
