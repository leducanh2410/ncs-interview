import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/workplace/index.page';

const {
    dataTableSpy,
    commonSearchBoxSpy,
    useGetWorkplacesQueryMock,
    useDeleteWorkplaceMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    routerMock,
} = vi.hoisted(() => ({
    dataTableSpy: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    useGetWorkplacesQueryMock: vi.fn(),
    useDeleteWorkplaceMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    routerMock: { query: {}, push: vi.fn() },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled, 'data-testid': 'action-icon' }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, 'aria-disabled': disabled, 'data-testid': 'create-btn' }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'create-modal' }, children) : null,
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (...args: unknown[]) => useDisclosureMock(...args),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplacesQuery: (...args: unknown[]) => useGetWorkplacesQueryMock(...args),
    useDeleteWorkplaceMutation: () => useDeleteWorkplaceMutationMock(),
}));

vi.mock('@/pages/admin/workplace/partials/workplace_form', () => ({
    default: () => React.createElement('div', { 'data-testid': 'workplace-form' }),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) => React.createElement('span', { key: el.id }, el.label)),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
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
                        props.columns.map((col: any) =>
                            React.createElement(
                                'td',
                                { key: col.key },
                                col.render ? col.render(record) : record[col.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

// Renders confirm/cancel buttons so callbacks can be triggered in tests.
vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel }: any) => {
        if (!opened) return null;
        return React.createElement(
            'div',
            { 'data-testid': 'confirm-modal' },
            React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'confirm-btn' }, 'Confirm'),
            React.createElement('button', { 'onClick': onCancel, 'data-testid': 'cancel-btn' }, 'Cancel'),
        );
    },
}));

const makeWorkplace = (overrides = {}) => ({
    id: 1,
    name: 'Tokyo HQ',
    address: '1-1 Chiyoda',
    coordinate: '35.68, 139.69',
    latitude: '35.68',
    longitude: '139.69',
    salary_per_hour: null,
    min_rest_hours: null,
    is_test: false,
    is_yamato: false,
    ...overrides,
});

describe('Workplace index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORKPLACE_LIST: true,
            WORKPLACE_CREATE: true,
            WORKPLACE_EDIT: true,
            WORKPLACE_DELETE: true,
        });

        useDeleteWorkplaceMutationMock.mockReturnValue({ mutateAsync: vi.fn().mockResolvedValue(undefined) });

        useGetWorkplacesQueryMock.mockReturnValue({
            data: {
                data: { data: [makeWorkplace()], total: 1, current_page: 1, last_page: 1, per_page: 15 },
            },
            isPending: false,
        });

        // Default: create modal closed
        useDisclosureMock.mockReturnValue([false, { open: vi.fn(), close: vi.fn() }]);
    });

    /****************************************
     * Search box                           *
     ****************************************/

    it('renders the search box with ID and Name fields', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('search-box')).toBeInTheDocument();
        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({ id: 'id', name: 'id' }),
                    expect.objectContaining({ id: 'name', name: 'name' }),
                ]),
            }),
        );
    });

    /****************************************
     * Table rows                           *
     ****************************************/

    it('renders a link to the workplace detail page for each row', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: 'Tokyo HQ' })).toHaveAttribute('href', '/admin/workplace/1');
    });

    it('does not show a TEST badge for a normal workplace', () => {
        render(<IndexPage />);

        expect(screen.queryByTestId('badge')).not.toBeInTheDocument();
    });

    it('shows a TEST badge for an is_test workplace', () => {
        useGetWorkplacesQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [makeWorkplace({ is_test: true })],
                    total: 1,
                    current_page: 1,
                    last_page: 1,
                    per_page: 15,
                },
            },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('badge')).toHaveTextContent('TEST');
    });

    /****************************************
     * Permissions                          *
     ****************************************/

    it('disables the Create Workplace button when WORKPLACE_CREATE is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_LIST: true,
            WORKPLACE_CREATE: false,
            WORKPLACE_EDIT: false,
            WORKPLACE_DELETE: true,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('create-btn')).toHaveAttribute('aria-disabled', 'true');
    });

    it('disables the delete action icon when WORKPLACE_DELETE is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_LIST: true,
            WORKPLACE_CREATE: true,
            WORKPLACE_EDIT: true,
            WORKPLACE_DELETE: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('action-icon')).toBeDisabled();
    });

    it('passes WORKPLACE_LIST permission to useGetWorkplacesQuery', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_LIST: false,
            WORKPLACE_CREATE: false,
            WORKPLACE_EDIT: false,
            WORKPLACE_DELETE: false,
        });

        render(<IndexPage />);

        expect(useGetWorkplacesQueryMock).toHaveBeenCalledWith(expect.anything(), false);
    });

    it('renders an empty table while data is loading', () => {
        useGetWorkplacesQueryMock.mockReturnValue({ data: undefined, isPending: true });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ data: [] }));
    });

    /****************************************
     * Create modal                         *
     ****************************************/

    it('renders the WorkplaceForm inside the modal when the create modal is open', () => {
        useDisclosureMock.mockReturnValue([true, { open: vi.fn(), close: vi.fn() }]);

        render(<IndexPage />);

        expect(screen.getByTestId('create-modal')).toBeInTheDocument();
        expect(screen.getByTestId('workplace-form')).toBeInTheDocument();
    });

    /****************************************
     * Delete flow                          *
     ****************************************/

    it('opens the confirm modal when the delete icon is clicked', () => {
        render(<IndexPage />);

        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('action-icon'));

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
    });

    it('calls deleteWorkplace and shows success notification on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteWorkplaceMutationMock.mockReturnValue({ mutateAsync });

        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('action-icon'));
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('1');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when deleteWorkplace fails', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(new Error('server error'));
        useDeleteWorkplaceMutationMock.mockReturnValue({ mutateAsync });

        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('action-icon'));
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'red' }));
        });
    });

    it('closes the confirm modal when cancel is clicked', () => {
        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('action-icon'));
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-btn'));
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });
});
