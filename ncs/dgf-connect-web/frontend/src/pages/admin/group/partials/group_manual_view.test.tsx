import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupManualView from '@/pages/admin/group/partials/group_manual_view';

const { attachMutateAsyncMock, detachMutateAsyncMock, updateMutateAsyncMock, showSuccessMock, usePermissionsMock } =
    vi.hoisted(() => ({
        attachMutateAsyncMock: vi.fn(),
        detachMutateAsyncMock: vi.fn(),
        updateMutateAsyncMock: vi.fn(),
        showSuccessMock: vi.fn(),
        usePermissionsMock: vi.fn(),
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: {
        type: '3rdParty',
        init: () => undefined,
    },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;

            return Object.entries(options).reduce(
                (result, [placeholder, value]) => result.replace(`{{${placeholder}}}`, String(value)),
                key,
            );
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, ...props }: any) => React.createElement('button', { type: 'button', ...props }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, loading, ...props }: any) =>
        React.createElement(
            'button',
            { type: props.type ?? 'button', disabled: loading || props.disabled, ...props },
            children,
        ),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Paper: ({ children }: any) => React.createElement('div', null, children),
    TextInput: ({ label, error, ...props }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                ...props,
                value: props.value ?? '',
                onChange: props.onChange,
            }),
            error ? React.createElement('div', null, error) : null,
        ),
    Title: ({ children }: any) => React.createElement('h2', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: vi.fn(),
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) => {
        const records = Array.isArray(data) ? data : data.data;

        return React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        columns.map((column: any) =>
                            React.createElement(
                                'td',
                                { key: column.key },
                                column.render ? column.render(record) : record[column.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/components/DUserSelectModal', () => ({
    default: ({ isOpen, title, onConfirm, confirmButtonLabel }: any) => {
        const [selectedUserId, setSelectedUserId] = React.useState('');

        return isOpen
            ? React.createElement(
                  'div',
                  { 'data-testid': 'user-select-modal' },
                  React.createElement('div', null, title),
                  React.createElement(
                      'label',
                      null,
                      'Employee',
                      React.createElement('input', {
                          'aria-label': 'Employee',
                          'value': selectedUserId,
                          'onChange': (event: React.ChangeEvent<HTMLInputElement>) =>
                              setSelectedUserId(event.currentTarget.value),
                      }),
                  ),
                  React.createElement(
                      'button',
                      { type: 'button', onClick: () => onConfirm([{ id: selectedUserId }]) },
                      confirmButtonLabel,
                  ),
              )
            : null;
    },
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, title, onConfirm, confirmButtonLabel }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('div', null, title),
                  React.createElement(
                      'button',
                      { type: 'button', onClick: onConfirm },
                      confirmButtonLabel ?? 'Confirm',
                  ),
              )
            : null,
}));

vi.mock('@/pages/admin/group/api', () => ({
    useUpdateGroupMutation: () => ({
        mutateAsync: updateMutateAsyncMock,
        isPending: false,
    }),
    useAttachGroupMemberMutation: () => ({
        mutateAsync: attachMutateAsyncMock,
        isPending: false,
    }),
    useBatchAttachGroupMembersMutation: () => ({
        mutateAsync: attachMutateAsyncMock,
        isPending: false,
    }),
    useDetachGroupMemberMutation: () => ({
        mutateAsync: detachMutateAsyncMock,
        isPending: false,
    }),
}));

const group = {
    id: 5,
    kind: 'manual' as const,
    name: 'Manual Team',
    display_name: { en: 'Manual Team', ja: 'Manual Team', vi: 'Manual Team' },
    source_workplace_id: null,
    source_workplace: null,
    source_prefecture: null,
    source_tier: null,
    predicate: null,
    member_count: 1,
    members: {
        data: [
            {
                id: 7,
                name: 'User One',
                staff_no: 'A-001',
                current_workplaces: [{ id: 1, name: 'Tokyo' }],
            },
        ],
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 1,
    },
};

describe('GroupManualView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            GROUP_EDIT: true,
        });
    });

    it('adds a member through the modal flow', async () => {
        const user = userEvent.setup();

        render(<GroupManualView group={group} isFetching={false} onMembersPageChange={vi.fn()} />);

        await user.click(screen.getByRole('button', { name: 'Add members' }));
        await user.type(screen.getByLabelText('Employee'), '42');
        await user.click(within(screen.getByTestId('user-select-modal')).getByRole('button', { name: 'Add members' }));

        await waitFor(() => {
            expect(attachMutateAsyncMock).toHaveBeenCalledWith(['42']);
        });

        expect(showSuccessMock).toHaveBeenCalledWith('Successfully added members');
    });

    it('removes a member after confirmation', async () => {
        const user = userEvent.setup();

        render(<GroupManualView group={group} isFetching={false} onMembersPageChange={vi.fn()} />);

        await user.click(within(screen.getByRole('row', { name: /User One/ })).getByRole('button'));
        await user.click(screen.getByRole('button', { name: 'Remove member' }));

        await waitFor(() => {
            expect(detachMutateAsyncMock).toHaveBeenCalledWith(7);
        });

        expect(showSuccessMock).toHaveBeenCalledWith('Successfully removed member');
    });
});
