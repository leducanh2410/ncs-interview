import React from 'react';
import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/group/index.page';

const { commonSearchBoxSpy, dataTableSpy, useGetGroupsQueryMock, usePermissionsMock, routerMock } = vi.hoisted(() => ({
    commonSearchBoxSpy: vi.fn(),
    dataTableSpy: vi.fn(),
    useGetGroupsQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    routerMock: {
        query: {},
        push: vi.fn(),
    },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: {
        type: '3rdParty',
        init: () => undefined,
    },
    useTranslation: () => ({
        t: (key: string) => key,
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, href, disabled }: any) =>
        React.createElement('a', { href, 'aria-disabled': disabled }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/group/api', () => ({
    useGetGroupsQuery: (...args: unknown[]) => useGetGroupsQueryMock(...args),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);

        return React.createElement(
            'div',
            { 'data-testid': 'group-search-box' },
            props.elements.map((element: any) =>
                React.createElement(
                    'div',
                    { key: element.id },
                    React.createElement('span', null, element.label),
                    element.options
                        ? React.createElement(
                              'ul',
                              null,
                              element.options.map((option: any) =>
                                  React.createElement('li', { key: option.value }, option.label),
                              ),
                          )
                        : null,
                ),
            ),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);

        const records = Array.isArray(props.data) ? props.data : props.data.data;

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
                        props.columns.map((column: any) =>
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

describe('Group index page', () => {
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
                        {
                            id: 11,
                            kind: 'manual',
                            name: 'Manual Team',
                            display_name: { en: 'Manual Team', ja: 'Manual Team', vi: 'Manual Team' },
                            source_workplace_id: null,
                            source_workplace: null,
                            source_prefecture: null,
                            source_tier: null,
                            predicate: null,
                            member_count: 3,
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 15,
                    total: 1,
                },
            },
            isPending: false,
        });
    });

    it('configures the search form with a search field and multi-select kind filter', () => {
        render(<IndexPage />);

        const searchBox = screen.getByTestId('group-search-box');

        expect(searchBox).toBeInTheDocument();
        expect(within(searchBox).getByText('Search')).toBeInTheDocument();
        expect(within(searchBox).getByText('Kind')).toBeInTheDocument();
        expect(within(searchBox).getByText('Current workplace')).toBeInTheDocument();
        expect(within(searchBox).getByText('Manual')).toBeInTheDocument();
        expect(within(searchBox).getByText('Rule based')).toBeInTheDocument();

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({
                        id: 'search',
                        name: 'search',
                    }),
                    expect.objectContaining({
                        id: 'kind',
                        name: 'kind',
                        isMulti: true,
                    }),
                ]),
            }),
        );
    });

    it('renders group links to the detail page', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: 'Manual Team' })).toHaveAttribute('href', '/admin/group/11');
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(dataTableSpy).toHaveBeenCalled();
    });
});
