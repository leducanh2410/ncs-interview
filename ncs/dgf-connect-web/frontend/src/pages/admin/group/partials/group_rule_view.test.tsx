import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupRuleView from '@/pages/admin/group/partials/group_rule_view';

const { updateMutateAsyncMock, useGetGroupsQueryMock, usePermissionsMock, showSuccessMock } = vi.hoisted(() => ({
    updateMutateAsyncMock: vi.fn(),
    useGetGroupsQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showSuccessMock: vi.fn(),
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
        React.createElement('button', { type: 'button', disabled: loading || props.disabled, ...props }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    MultiSelect: ({ label, data, value, onChange, ...props }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement(
                'select',
                {
                    'aria-label': label,
                    'multiple': true,
                    'data-testid': `multiselect-${label ?? 'unknown'}`,
                    'value': value ?? [],
                    'onChange': (event: React.ChangeEvent<HTMLSelectElement>) => {
                        const selected = Array.from(event.currentTarget.selectedOptions, (o) => o.value);
                        onChange(selected);
                    },
                    ...props,
                },
                data.map((option: any) =>
                    React.createElement('option', { key: option.value, value: option.value }, option.label),
                ),
            ),
        ),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Select: ({ label, data, value, onChange, description, ...props }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement(
                'select',
                {
                    'aria-label': label,
                    'value': value ?? '',
                    'onChange': (event: React.ChangeEvent<HTMLSelectElement>) => onChange(event.currentTarget.value),
                    ...props,
                },
                React.createElement('option', { value: '' }, ''),
                data.map((option: any) =>
                    React.createElement('option', { key: option.value, value: option.value }, option.label),
                ),
            ),
            description ? React.createElement('div', null, description) : null,
        ),
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

vi.mock('@/pages/admin/group/api', () => ({
    useGetGroupsQuery: (...args: unknown[]) => useGetGroupsQueryMock(...args),
    useUpdateGroupMutation: () => ({
        mutateAsync: updateMutateAsyncMock,
        isPending: false,
    }),
}));

const group = {
    id: 8,
    kind: 'rule_based' as const,
    name: 'Tokyo Team',
    display_name: { en: 'Tokyo Team', ja: 'Tokyo Team', vi: 'Tokyo Team' },
    source_workplace_id: null,
    source_workplace: null,
    source_prefecture: null,
    source_tier: null,
    predicate: [{ field: 'prefecture' as const, values: ['tokyo'] }],
    member_count: 2,
    members: {
        data: [
            {
                id: 9,
                name: 'User Two',
                staff_no: 'A-002',
                current_workplaces: [{ id: 2, name: 'Osaka' }],
            },
        ],
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 1,
    },
};

describe('GroupRuleView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            GROUP_VIEW: true,
            GROUP_EDIT: true,
        });

        useGetGroupsQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        { id: 1, source_prefecture: 'tokyo' },
                        { id: 2, source_prefecture: 'osaka' },
                    ],
                },
            },
        });
    });

    it('saves edited predicate conditions', async () => {
        const user = userEvent.setup();

        render(<GroupRuleView group={group} isFetching={false} onMembersPageChange={vi.fn()} />);

        await user.clear(screen.getByLabelText('Name'));
        await user.type(screen.getByLabelText('Name'), '  Kansai Team  ');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(updateMutateAsyncMock).toHaveBeenCalledWith({
                name: 'Kansai Team',
                predicate: [{ field: 'prefecture', values: ['tokyo'] }],
            });
        });

        expect(showSuccessMock).toHaveBeenCalledWith('Successfully updated group');
    });
});
