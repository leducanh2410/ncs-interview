import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SupportTypeAdminUser, SupportTypeConfig } from '@/types/models/chat';
import SupportTypeConfigSection from '@/pages/admin/configuration/partials/support_type_config';

const { addMutateMock, removeMutateMock, addIsPendingMock, removeIsPendingMock } = vi.hoisted(() => ({
    addMutateMock: vi.fn(),
    removeMutateMock: vi.fn(),
    addIsPendingMock: { value: false },
    removeIsPendingMock: { value: false },
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string) => key,
    }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, loading, ...props }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading, ...props }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h2', null, children),
    Tooltip: ({ children, label }: any) => React.createElement('div', { title: label }, children),
}));

vi.mock('@/components/DAvatar', () => ({
    default: ({ name }: any) => React.createElement('div', { 'data-testid': `avatar-${name}` }),
}));

vi.mock('@/components/DSelectRemote', () => ({
    default: ({ value, onChange, placeholder }: any) =>
        React.createElement('input', {
            'data-testid': 'user-select',
            'value': value ?? '',
            'placeholder': placeholder,
            'onChange': (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value || null),
        }),
}));

vi.mock('@/pages/admin/chat/support/api', () => ({
    useAddSupportTypeAdminMutation: () => ({
        mutate: addMutateMock,
        isPending: addIsPendingMock.value,
    }),
    useRemoveSupportTypeAdminMutation: () => ({
        mutate: removeMutateMock,
        isPending: removeIsPendingMock.value,
    }),
}));

const makeAdmin = (overrides: {
    user_id: number;
    name: string;
    staff_no?: string | null;
    profile_picture?: string | null;
}): SupportTypeAdminUser => ({
    staff_no: null,
    profile_picture: null,
    ...overrides,
});

const makeConfig = (type: string, admins: ReturnType<typeof makeAdmin>[] = []): SupportTypeConfig => ({
    type: type as any,
    admins,
});

describe('SupportTypeConfigSection', () => {
    beforeEach(() => {
        addMutateMock.mockReset();
        removeMutateMock.mockReset();
    });

    it('shows loader while loading', () => {
        render(<SupportTypeConfigSection configs={undefined} isLoading={true} canEdit={false} />);
        expect(screen.getByTestId('loader')).toBeDefined();
    });

    it('renders a section per config type', () => {
        const configs = [makeConfig('shift'), makeConfig('salary')];
        render(<SupportTypeConfigSection configs={configs} isLoading={false} canEdit={false} />);
        expect(screen.getByText('support_type|shift')).toBeDefined();
        expect(screen.getByText('support_type|salary')).toBeDefined();
    });

    it('renders the Other support type section', () => {
        render(<SupportTypeConfigSection configs={[makeConfig('other')]} isLoading={false} canEdit={false} />);
        expect(screen.getByText('support_type|other')).toBeDefined();
    });

    it('shows empty state when no admins are assigned', () => {
        render(<SupportTypeConfigSection configs={[makeConfig('shift')]} isLoading={false} canEdit={false} />);
        expect(screen.getByText('No admins assigned')).toBeDefined();
    });

    it('renders admin names', () => {
        const configs = [makeConfig('shift', [makeAdmin({ user_id: 1, name: 'Nguyen Van A', staff_no: 'S001' })])];
        render(<SupportTypeConfigSection configs={configs} isLoading={false} canEdit={false} />);
        expect(screen.getByText('Nguyen Van A')).toBeDefined();
        expect(screen.getByText('(S001)')).toBeDefined();
    });

    it('hides add/remove controls when canEdit is false', () => {
        const configs = [makeConfig('shift', [makeAdmin({ user_id: 1, name: 'Admin', staff_no: 'S001' })])];
        render(<SupportTypeConfigSection configs={configs} isLoading={false} canEdit={false} />);
        expect(screen.queryByTestId('user-select')).toBeNull();
        expect(screen.queryByTitle('Remove')).toBeNull();
    });

    it('shows add/remove controls when canEdit is true', () => {
        const configs = [makeConfig('shift', [makeAdmin({ user_id: 1, name: 'Admin', staff_no: 'S001' })])];
        render(<SupportTypeConfigSection configs={configs} isLoading={false} canEdit={true} />);
        expect(screen.getByTestId('user-select')).toBeDefined();
        expect(screen.getByTitle('Remove')).toBeDefined();
    });

    it('calls removeMutation when Remove is clicked', async () => {
        const user = userEvent.setup();
        const configs = [makeConfig('shift', [makeAdmin({ user_id: 42, name: 'Admin' })])];
        render(<SupportTypeConfigSection configs={configs} isLoading={false} canEdit={true} />);
        await user.click(within(screen.getByTitle('Remove')).getByRole('button'));
        expect(removeMutateMock).toHaveBeenCalledWith({ type: 'shift', userId: 42 });
    });

    it('calls addMutation with selected userId when Add is clicked', async () => {
        const user = userEvent.setup();
        render(<SupportTypeConfigSection configs={[makeConfig('salary')]} isLoading={false} canEdit={true} />);

        await user.type(screen.getByTestId('user-select'), '7');
        await user.click(screen.getByRole('button'));

        expect(addMutateMock).toHaveBeenCalledWith(
            { type: 'salary', userId: 7 },
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });

    it('clears selection after successful add', async () => {
        const user = userEvent.setup();
        addMutateMock.mockImplementation((_data: any, { onSuccess }: any) => onSuccess());

        render(<SupportTypeConfigSection configs={[makeConfig('salary')]} isLoading={false} canEdit={true} />);

        await user.type(screen.getByTestId('user-select'), '7');
        await user.click(screen.getByRole('button'));

        expect((screen.getByTestId('user-select') as HTMLInputElement).value).toBe('');
    });
});
