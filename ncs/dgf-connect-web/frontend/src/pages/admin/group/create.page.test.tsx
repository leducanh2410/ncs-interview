import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiValidationError } from '@/helpers/api.helper';
import CreateGroupPage from '@/pages/admin/group/create.page';

const { createMutateAsyncMock, showSuccessMock, showErrorMock, routerPushMock } = vi.hoisted(() => ({
    createMutateAsyncMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    routerPushMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ push: routerPushMock, query: {} }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Box: ({ children, c }: any) => React.createElement('div', { 'data-color': c }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading, component, href }: any) =>
        component
            ? React.createElement('a', { href }, children)
            : React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    MultiSelect: ({ label, value, onChange }: any) =>
        React.createElement('select', {
            'aria-label': label ?? 'Values',
            'multiple': true,
            'value': value ?? [],
            'onChange': (e: any) => {
                const selected = Array.from(e.target.selectedOptions as HTMLCollectionOf<HTMLOptionElement>).map(
                    (o) => o.value,
                );
                onChange?.(selected);
            },
        }),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Select: ({ label, value, onChange, data }: any) =>
        React.createElement(
            'select',
            {
                'aria-label': label,
                'value': value ?? '',
                'onChange': (e: any) => onChange?.(e.target.value),
            },
            (data ?? []).map((opt: any) =>
                React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
            ),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ label, value, onChange, error }: any) =>
        React.createElement(
            'div',
            null,
            React.createElement('input', {
                'aria-label': label,
                'value': value ?? '',
                onChange,
            }),
            error ? React.createElement('span', { 'data-testid': 'name-error' }, error) : null,
        ),
    Title: ({ children }: any) => React.createElement('h2', null, children),
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiValidationError: class ApiValidationError extends Error {
        data: Record<string, string[]>;
        constructor({ data }: { status: number; message: string; data: Record<string, string[]> }) {
            super('Validation Error');
            this.data = data;
        }
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { toSelectArray: () => [] },
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        ADMIN_GROUP_LIST: { path: '/admin/group' },
        ADMIN_GROUP_DETAIL: { path: '/admin/group/[id]' },
    },
}));

vi.mock('@/enums/gender.enum', () => ({ GenderEnum: {} }));
vi.mock('@/enums/japanese_level.enum', () => ({ JapaneseLevelEnum: {} }));
vi.mock('@/enums/nationality.enum', () => ({ NationalityEnum: {} }));
vi.mock('@/enums/prefecture.enum', () => ({ PrefectureEnum: {} }));

vi.mock('react-icons/lu', () => ({
    LuPlus: () => React.createElement('span', null, '+'),
    LuSave: () => React.createElement('span', null, '💾'),
    LuTrash2: () => React.createElement('span', null, '🗑'),
}));

vi.mock('@/pages/admin/group/api', () => ({
    useCreateGroupMutation: () => ({ mutateAsync: createMutateAsyncMock, isPending: false }),
}));

describe('CreateGroupPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the name input and kind selector', () => {
        render(<CreateGroupPage />);

        expect(screen.getByRole('combobox', { name: 'Kind' })).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Name' })).toBeInTheDocument();
    });

    it('hides the conditions section for manual kind (default)', () => {
        render(<CreateGroupPage />);

        expect(screen.queryByText('Conditions')).not.toBeInTheDocument();
    });

    it('shows the conditions section when kind is switched to rule_based', async () => {
        const user = userEvent.setup();

        render(<CreateGroupPage />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'rule_based');

        expect(screen.getByText('Conditions')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add condition' })).toBeInTheDocument();
    });

    it('adds a new clause row when "Add condition" is clicked', async () => {
        const user = userEvent.setup();

        render(<CreateGroupPage />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'rule_based');

        const before = screen.getAllByRole('button', { name: '🗑' }).length;
        await user.click(screen.getByRole('button', { name: 'Add condition' }));

        expect(screen.getAllByRole('button', { name: '🗑' }).length).toBe(before + 1);
    });

    it('keeps the remove button disabled when only one clause remains', async () => {
        const user = userEvent.setup();

        render(<CreateGroupPage />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'rule_based');

        expect(screen.getByRole('button', { name: '🗑' })).toBeDisabled();
    });

    it('enables remove buttons when more than one clause exists', async () => {
        const user = userEvent.setup();

        render(<CreateGroupPage />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'rule_based');
        await user.click(screen.getByRole('button', { name: 'Add condition' }));

        const removeButtons = screen.getAllByRole('button', { name: '🗑' });
        removeButtons.forEach((btn) => expect(btn).not.toBeDisabled());
    });

    it('shows a name validation error when saving with empty name', async () => {
        const user = userEvent.setup();

        render(<CreateGroupPage />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(screen.getByTestId('name-error')).toBeInTheDocument();
        expect(createMutateAsyncMock).not.toHaveBeenCalled();
    });

    it('shows predicate error when saving rule_based with no values selected', async () => {
        const user = userEvent.setup();

        render(<CreateGroupPage />);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'rule_based');
        await user.type(screen.getByRole('textbox', { name: 'Name' }), 'My Rule Group');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(screen.getByText('Each condition must have a field and value.')).toBeInTheDocument();
        expect(createMutateAsyncMock).not.toHaveBeenCalled();
    });

    it('calls mutation and redirects to group detail on successful manual creation', async () => {
        const user = userEvent.setup();
        createMutateAsyncMock.mockResolvedValue({ data: { id: 42 } });

        render(<CreateGroupPage />);

        await user.type(screen.getByRole('textbox', { name: 'Name' }), 'Team Bravo');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(createMutateAsyncMock).toHaveBeenCalledWith({ kind: 'manual', name: 'Team Bravo' });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully created group');
        expect(routerPushMock).toHaveBeenCalledWith('/admin/group/42');
    });

    it('redirects to group list when response has no id', async () => {
        const user = userEvent.setup();
        createMutateAsyncMock.mockResolvedValue({ data: null });

        render(<CreateGroupPage />);

        await user.type(screen.getByRole('textbox', { name: 'Name' }), 'Team Charlie');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(routerPushMock).toHaveBeenCalledWith('/admin/group');
        });
    });

    it('sets field errors from ApiValidationError response', async () => {
        const user = userEvent.setup();
        createMutateAsyncMock.mockRejectedValue(
            new ApiValidationError({
                status: 422,
                message: 'Validation failed',
                data: { name: ['The name has already been taken.'] },
            }),
        );

        render(<CreateGroupPage />);

        await user.type(screen.getByRole('textbox', { name: 'Name' }), 'Duplicate');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByTestId('name-error')).toBeInTheDocument();
        });
        expect(screen.getByTestId('name-error')).toHaveTextContent('validation|The name has already been taken.');
        expect(showErrorMock).not.toHaveBeenCalled();
    });

    it('shows generic error notification for non-validation errors', async () => {
        const user = userEvent.setup();
        createMutateAsyncMock.mockRejectedValue(new Error('Network error'));

        render(<CreateGroupPage />);

        await user.type(screen.getByRole('textbox', { name: 'Name' }), 'My Group');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not create group');
        });
    });
});
