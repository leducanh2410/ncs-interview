import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceInstructionCreateUpdate from './workplace_instruction_create_update';

const { useCreateWorkplaceInstructionMutationMock, useUpdateWorkplaceInstructionMutationMock, notificationsMock } =
    vi.hoisted(() => ({
        useCreateWorkplaceInstructionMutationMock: vi.fn(),
        useUpdateWorkplaceInstructionMutationMock: vi.fn(),
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
    AspectRatio: ({ children }: any) => React.createElement('div', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, type, loading, ...rest }: any) =>
        React.createElement('button', { onClick, type: type || 'button', disabled: loading, ...rest }, children),
    FileButton: ({ children, onChange }: any) =>
        React.createElement('div', null, [
            React.createElement('input', {
                'key': 'file',
                'type': 'file',
                'data-testid': 'file-input',
                'onChange': (e: any) => onChange?.(e.target.files[0] ?? null),
            }),
            children({}),
        ]),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Image: ({ alt }: any) => React.createElement('img', { alt }),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    Select: ({ label, data, value, onChange, error }: any) =>
        React.createElement('div', null, [
            React.createElement(
                'select',
                {
                    'key': 'sel',
                    'aria-label': label,
                    'value': value ?? '',
                    'onChange': (e: any) => onChange?.(e.target.value || null),
                },
                [
                    React.createElement('option', { key: '', value: '' }, ''),
                    ...(data ?? []).map((opt: any) =>
                        React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                    ),
                ],
            ),
            error ? React.createElement('span', { key: 'err', role: 'alert' }, error) : null,
        ]),
    Text: ({ children }: any) => React.createElement('span', null, children),
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
            setFieldValue: (field: string, value: any) => setValues((prev: any) => ({ ...prev, [field]: value })),
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
    useCreateWorkplaceInstructionMutation: () => useCreateWorkplaceInstructionMutationMock(),
    useUpdateWorkplaceInstructionMutation: () => useUpdateWorkplaceInstructionMutationMock(),
}));

vi.mock('react-icons/lu', () => ({
    LuSave: () => React.createElement('span', null, 'save-icon'),
}));

const makeInstruction = (overrides: Record<string, any> = {}) =>
    ({
        id: 3,
        workplace_id: 1,
        type: 'link',
        title: 'Guide',
        content: 'https://example.com',
        ...overrides,
    }) as any;

function renderComponent(props: Partial<React.ComponentProps<typeof WorkplaceInstructionCreateUpdate>> = {}) {
    const defaults = {
        workplaceId: '1',
        instruction: null,
        opened: true,
        onClose: vi.fn(),
    };
    return { onClose: defaults.onClose, ...render(<WorkplaceInstructionCreateUpdate {...defaults} {...props} />) };
}

describe('WorkplaceInstructionCreateUpdate', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        useCreateWorkplaceInstructionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useUpdateWorkplaceInstructionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('renders nothing when closed', () => {
        renderComponent({ opened: false });

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('shows "Create instruction" title when no instruction is passed', () => {
        renderComponent({ instruction: null });

        expect(screen.getByText('Create instruction')).toBeInTheDocument();
    });

    it('shows "Edit instruction" title and pre-fills fields when an instruction is passed', () => {
        renderComponent({ instruction: makeInstruction({ title: 'FAQ' }) });

        expect(screen.getByText('Edit instruction')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Title' })).toHaveValue('FAQ');
        expect(screen.getByRole('combobox', { name: 'Type' })).toHaveValue('link');
    });

    it('shows the URL field for non-image types and hides it for image type', async () => {
        renderComponent({ instruction: null });

        expect(screen.getByRole('textbox', { name: 'URL' })).toBeInTheDocument();

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Type' }), 'image');

        expect(screen.queryByRole('textbox', { name: 'URL' })).not.toBeInTheDocument();
        expect(screen.getByTestId('file-input')).toBeInTheDocument();
    });

    it('blocks submit and does not call the mutation when required fields are empty', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ instruction: null });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByRole('alert').length).toBeGreaterThan(0);
        });
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('calls createMutation with link params and shows success notification', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();

        renderComponent({ instruction: null, onClose });

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Type' }), 'link');
        await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Guide');
        await userEvent.type(screen.getByRole('textbox', { name: 'URL' }), 'https://example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({ type: 'link', title: 'Guide', content: 'https://example.com' }),
            );
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
            expect(onClose).toHaveBeenCalled();
        });
    });

    it('calls updateMutation with the instructionId when editing', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useUpdateWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ instruction: makeInstruction({ id: 9 }) });

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.objectContaining({ instructionId: '9' }));
        });
    });

    it('uploads a FormData payload with the selected file for image type', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useCreateWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        renderComponent({ instruction: null });

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Type' }), 'image');
        await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Banner');

        const file = new File(['content'], 'banner.png', { type: 'image/png' });
        await userEvent.upload(screen.getByTestId('file-input'), file);

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(expect.any(FormData));
            const formData = mutateAsync.mock.calls[0][0] as FormData;
            expect(formData.get('type')).toBe('image');
            expect(formData.get('title')).toBe('Banner');
            expect(formData.get('file')).toBe(file);
        });
    });

    it('calls onClose when Cancel is clicked without submitting the form', async () => {
        const mutateAsync = vi.fn();
        useCreateWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        const onClose = vi.fn();
        renderComponent({ onClose });

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalled();
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('disables the Save button while a mutation is pending', () => {
        useCreateWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });

        renderComponent({ instruction: null });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
