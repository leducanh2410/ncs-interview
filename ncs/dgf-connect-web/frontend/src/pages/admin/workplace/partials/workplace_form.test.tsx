import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceForm from './workplace_form';

const { useCreateWorkplaceMutationMock, useUpdateWorkplaceMutationMock, usePermissionsMock, notificationsMock } =
    vi.hoisted(() => ({
        useCreateWorkplaceMutationMock: vi.fn(),
        useUpdateWorkplaceMutationMock: vi.fn(),
        usePermissionsMock: vi.fn(),
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
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, type, loading, disabled }: any) =>
        React.createElement('button', { onClick, type: type || 'button', disabled: disabled || loading }, children),
    Checkbox: ({ label, checked, onChange, disabled }: any) =>
        React.createElement('label', null, [
            React.createElement('input', {
                key: 'cb',
                type: 'checkbox',
                checked,
                disabled,
                onChange: () => onChange?.({ currentTarget: { checked: !checked } }),
            }),
            label,
        ]),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    NumberInput: ({ value, onChange, disabled }: any) =>
        React.createElement('input', {
            type: 'number',
            value: value ?? '',
            disabled: disabled,
            onChange: (e: any) => onChange?.(e.target.value === '' ? '' : Number(e.target.value)),
        }),
    SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
    Skeleton: () => React.createElement('div', { 'data-testid': 'skeleton' }),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ label, value, onChange, disabled, error }: any) =>
        React.createElement('div', null, [
            React.createElement('label', { key: 'label' }, label),
            React.createElement('input', {
                key: 'input',
                value: value ?? '',
                disabled,
                onChange: (e: any) => onChange?.(e.target.value),
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
            getInputProps: (field: string, opts?: any) => {
                if (opts?.type === 'checkbox') {
                    return {
                        checked: !!values[field],
                        onChange: (e: any) => setValues((prev: any) => ({ ...prev, [field]: e.currentTarget.checked })),
                    };
                }
                return {
                    value: values[field] ?? '',
                    onChange: (v: any) => setValues((prev: any) => ({ ...prev, [field]: v })),
                    error: errors[field],
                };
            },
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

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useCreateWorkplaceMutation: () => useCreateWorkplaceMutationMock(),
    useUpdateWorkplaceMutation: (...args: unknown[]) => useUpdateWorkplaceMutationMock(...args),
}));

vi.mock('react-icons/lu', () => ({
    LuPencilLine: () => React.createElement('span', null, 'edit-icon'),
    LuSave: () => React.createElement('span', null, 'save-icon'),
    LuX: () => React.createElement('span', null, 'x-icon'),
}));

const makeWorkplace = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        name: 'Tokyo HQ',
        address: '1-1 Chiyoda',
        latitude: '35.68',
        longitude: '139.69',
        salary_per_hour: null,
        min_rest_hours: null,
        overtime_after_hours: null,
        is_test: false,
        is_yamato: false,
        is_self_register_disabled: false,
        ...overrides,
    }) as any;

describe('WorkplaceForm', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({ WORKPLACE_EDIT: true, WORKPLACE_CREATE: true });
        useCreateWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    /****************************************
     * Create mode                          *
     ****************************************/

    it('renders enabled inputs and a Save/Cancel footer in create mode', () => {
        render(<WorkplaceForm id={null} workplace={null} isFetching={false} />);

        expect(screen.getAllByRole('textbox')[0]).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    });

    it('blocks submit and does not call the mutation when required fields are empty', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<WorkplaceForm id={null} workplace={null} isFetching={false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByRole('alert').length).toBeGreaterThan(0);
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('calls createWorkplace and shows a success notification', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onDone = vi.fn();

        render(<WorkplaceForm id={null} workplace={null} isFetching={false} onDone={onDone} />);

        const [nameInput, addressInput, coordinatesInput] = screen.getAllByRole('textbox');
        await userEvent.type(nameInput, 'Osaka Branch');
        await userEvent.type(addressInput, '1-1 Namba');
        await userEvent.type(coordinatesInput, '34.66, 135.50');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({ name: 'Osaka Branch', address: '1-1 Namba', coordinates: '34.66, 135.50' }),
            );
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully created workplace' }),
            );
            expect(onDone).toHaveBeenCalled();
        });
    });

    it('disables the Save button when WORKPLACE_CREATE is false', () => {
        usePermissionsMock.mockReturnValue({ WORKPLACE_EDIT: true, WORKPLACE_CREATE: false });

        render(<WorkplaceForm id={null} workplace={null} isFetching={false} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('resets the form and calls onCancel when Cancel is clicked in create mode', async () => {
        const onCancel = vi.fn();
        render(<WorkplaceForm id={null} workplace={null} isFetching={false} onCancel={onCancel} />);

        await userEvent.type(screen.getAllByRole('textbox')[0], 'Draft name');
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onCancel).toHaveBeenCalled();
        expect(screen.getAllByRole('textbox')[0]).toHaveValue('');
    });

    /****************************************
     * Edit mode                            *
     ****************************************/

    it('shows a read-only view with an Edit button when a workplace is passed', () => {
        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
        expect(screen.getAllByRole('textbox')[0]).toBeDisabled();
    });

    it('pre-fills fields from the workplace', () => {
        render(<WorkplaceForm id="1" workplace={makeWorkplace({ name: 'Osaka Branch' })} isFetching={false} />);

        expect(screen.getAllByRole('textbox')[0]).toHaveValue('Osaka Branch');
        expect(screen.getAllByRole('textbox')[2]).toHaveValue('35.68, 139.69');
    });

    it('renders a Google Maps link based on the coordinates', () => {
        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        const link = screen.getByRole('link', { name: 'Open Google Maps' });
        expect(link).toHaveAttribute('href', 'https://www.google.com/maps/search/?api=1&query=35.68, 139.69');
    });

    it('switches to the editable view when Edit is clicked', async () => {
        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));

        expect(screen.getByRole('button', { name: 'Save changes' })).toBeInTheDocument();
        expect(screen.getAllByRole('textbox')[0]).toBeEnabled();
    });

    it('disables the Edit button when WORKPLACE_EDIT is false', () => {
        usePermissionsMock.mockReturnValue({ WORKPLACE_EDIT: false, WORKPLACE_CREATE: true });

        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
    });

    it('calls updateWorkplace and shows a success notification, then returns to read-only', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onDone = vi.fn();

        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} onDone={onDone} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ name: 'Tokyo HQ' }));
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully updated workplace' }),
            );
            expect(onDone).toHaveBeenCalled();
        });
        expect(useUpdateWorkplaceMutationMock).toHaveBeenCalledWith('1');
        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
    });

    it('pre-checks the self-registration lock from the workplace', () => {
        render(
            <WorkplaceForm id="1" workplace={makeWorkplace({ is_self_register_disabled: true })} isFetching={false} />,
        );

        expect(
            screen.getByRole('checkbox', { name: 'Do not allow users to register for this workplace themselves' }),
        ).toBeChecked();
    });

    it('submits the self-registration lock when it is toggled on', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));
        await userEvent.click(
            screen.getByRole('checkbox', { name: 'Do not allow users to register for this workplace themselves' }),
        );
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ is_self_register_disabled: true }));
        });
    });

    it('submits the overtime threshold as typed', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));
        // salary_per_hour, min_rest_hours, overtime_after_hours
        const overtimeInput = screen.getAllByRole('spinbutton')[2];
        await userEvent.type(overtimeInput, '9');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ overtime_after_hours: 9 }));
        });
    });

    it('pre-fills the overtime threshold from the workplace', () => {
        render(<WorkplaceForm id="1" workplace={makeWorkplace({ overtime_after_hours: 9 })} isFetching={false} />);

        expect(screen.getAllByRole('spinbutton')[2]).toHaveValue(9);
    });

    it('resets to read-only and calls onCancel when Cancel is clicked while editing', async () => {
        const onCancel = vi.fn();
        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} onCancel={onCancel} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onCancel).toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
    });

    it('shows a specific error notification for a test-membership mismatch', async () => {
        const { ApiError } = await import('@/helpers/api.helper');
        const error = new ApiError({ status: 400, message: 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH', data: {} });
        useUpdateWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });

        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({
                    color: 'red',
                    message: 'error|Cannot change test status while members of the opposite type are assigned',
                }),
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
        useUpdateWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });

        render(<WorkplaceForm id="1" workplace={makeWorkplace()} isFetching={false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Edit' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => {
            expect(screen.getByText('validation|The name has already been taken.')).toBeInTheDocument();
        });
    });

    /****************************************
     * Loading state                        *
     ****************************************/

    it('shows skeletons instead of inputs while fetching', () => {
        render(<WorkplaceForm id="1" workplace={undefined} isFetching={true} />);

        expect(screen.getAllByTestId('skeleton').length).toBeGreaterThan(0);
        expect(screen.queryAllByRole('textbox')).toHaveLength(0);
    });
});
