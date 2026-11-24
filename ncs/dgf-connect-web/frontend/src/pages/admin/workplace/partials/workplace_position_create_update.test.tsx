import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplacePositionCreateUpdate from './workplace_position_create_update';

const { useCreateWorkplacePositionMutationMock, useUpdateWorkplacePositionMutationMock, notificationsMock } =
    vi.hoisted(() => ({
        useCreateWorkplacePositionMutationMock: vi.fn(),
        useUpdateWorkplacePositionMutationMock: vi.fn(),
        notificationsMock: { show: vi.fn() },
    }));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
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
    NumberInput: ({ label, value, onChange }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange?.(e.target.value),
        }),
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

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues, validate }: any) => {
        const [values, setValues] = React.useState(initialValues);
        const [errors, setErrors] = React.useState<Record<string, string>>({});

        return {
            values,
            errors,
            setValues: (v: any) => setValues((prev: any) => ({ ...prev, ...v })),
            setFieldError: (field: string, msg: string) => setErrors((prev) => ({ ...prev, [field]: msg })),
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
                let validationErrors: Record<string, string> = {};
                if (typeof validate === 'function') {
                    validationErrors = validate(values);
                } else if (validate) {
                    for (const field of Object.keys(validate)) {
                        const message = validate[field](values[field], values, field);
                        if (message) {
                            validationErrors[field] = message;
                        }
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

vi.mock('@/pages/admin/workplace/api', () => ({
    useCreateWorkplacePositionMutation: () => useCreateWorkplacePositionMutationMock(),
    useUpdateWorkplacePositionMutation: () => useUpdateWorkplacePositionMutationMock(),
}));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, 'save-icon'),
}));

const makePosition = (overrides: Record<string, any> = {}) =>
    ({
        id: 5,
        workplace_id: 1,
        name: 'Cashier',
        salary_per_hour: 1200,
        is_active: true,
        ...overrides,
    }) as any;

function renderComponent(props: Partial<React.ComponentProps<typeof WorkplacePositionCreateUpdate>> = {}) {
    const defaults = {
        workplaceId: '1',
        position: null,
        opened: true,
        onClose: vi.fn(),
    };
    return { onClose: defaults.onClose, ...render(<WorkplacePositionCreateUpdate {...defaults} {...props} />) };
}

describe('WorkplacePositionCreateUpdate', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useCreateWorkplacePositionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateWorkplacePositionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('renders nothing when closed', () => {
        renderComponent({ opened: false });

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('shows "Create position" title when no position is passed', () => {
        renderComponent({ position: null });

        expect(screen.getByText('Create position')).toBeInTheDocument();
    });

    it('shows "Edit position" title and pre-fills the name when a position is passed', () => {
        renderComponent({ position: makePosition({ name: 'Manager' }) });

        expect(screen.getByText('Edit position')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Position' })).toHaveValue('Manager');
    });

    it('blocks submit and does not call the mutation when name is empty', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplacePositionMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ position: null });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByRole('alert')).toBeInTheDocument();
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('calls createMutation and shows success notification on create', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateWorkplacePositionMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();

        renderComponent({ position: null, onClose });

        await userEvent.type(screen.getByRole('textbox', { name: 'Position' }), 'Cashier');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ name: 'Cashier' }));
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('calls updateMutation with the positionId when editing', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateWorkplacePositionMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ position: makePosition({ id: 7, name: 'Manager' }) });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({ positionId: '7', data: expect.objectContaining({ name: 'Manager' }) }),
            );
        });
    });

    it('sets a field error from an API validation error response', async () => {
        const { ApiValidationError } = await import('@/helpers/api.helper');
        const error = new ApiValidationError({
            status: 422,
            message: 'Validation error',
            data: { name: ['The name has already been taken.'] },
        });
        useCreateWorkplacePositionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });

        renderComponent({ position: null });

        await userEvent.type(screen.getByRole('textbox', { name: 'Position' }), 'Cashier');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('validation|The name has already been taken.')).toBeInTheDocument();
        });
    });

    it('calls onClose when Cancel is clicked without submitting the form', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplacePositionMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();
        renderComponent({ onClose });

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalled();
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('disables the Save button while a mutation is pending', () => {
        useCreateWorkplacePositionMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });

        renderComponent({ position: null });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
