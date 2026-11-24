import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceInstructionList from './workplace_instruction';

const {
    useGetWorkplaceInstructionsQueryMock,
    useDeleteWorkplaceInstructionMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    dataTableSpy,
    createUpdateSpy,
} = vi.hoisted(() => ({
    useGetWorkplaceInstructionsQueryMock: vi.fn(),
    useDeleteWorkplaceInstructionMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    dataTableSpy: vi.fn(),
    createUpdateSpy: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (options) {
                return Object.keys(options).reduce((acc, k) => acc.replace(`{{${k}}}`, String(options[k])), key);
            }
            return key;
        },
    }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (...args: unknown[]) => useDisclosureMock(...args),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled, 'data-testid': 'action-icon' }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled, 'data-testid': 'add-btn' }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceInstructionsQuery: (...args: unknown[]) => useGetWorkplaceInstructionsQueryMock(...args),
    useDeleteWorkplaceInstructionMutation: () => useDeleteWorkplaceInstructionMutationMock(),
}));

vi.mock('@/pages/admin/workplace/partials/workplace_instruction_create_update', () => ({
    default: (props: any) => {
        createUpdateSpy(props);
        return props.opened ? React.createElement('div', { 'data-testid': 'create-update-modal' }) : null;
    },
}));

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

vi.mock('react-icons/lu', () => ({
    LuExternalLink: () => React.createElement('span', null, 'ext-link'),
    LuPencilLine: () => React.createElement('span', null, 'edit'),
    LuPlus: () => React.createElement('span', null, 'plus'),
    LuTrash2: () => React.createElement('span', null, 'trash'),
}));

const makeInstruction = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        workplace_id: 1,
        type: 'link',
        title: 'Guide',
        content: 'https://example.com',
        ...overrides,
    }) as any;

describe('WorkplaceInstructionList', () => {
    let modalOpen: ReturnType<typeof vi.fn>;
    let modalClose: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORKPLACE_INSTRUCTION_LIST: true,
            WORKPLACE_INSTRUCTION_CREATE: true,
            WORKPLACE_INSTRUCTION_EDIT: true,
            WORKPLACE_INSTRUCTION_DELETE: true,
        });
        useDeleteWorkplaceInstructionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useGetWorkplaceInstructionsQueryMock.mockReturnValue({ data: { data: [makeInstruction()] }, isLoading: false });

        modalOpen = vi.fn();
        modalClose = vi.fn();
        useDisclosureMock.mockReturnValue([false, { open: modalOpen, close: modalClose }]);
    });

    it('passes WORKPLACE_INSTRUCTION_LIST permission to the query', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_INSTRUCTION_LIST: false,
            WORKPLACE_INSTRUCTION_CREATE: false,
            WORKPLACE_INSTRUCTION_EDIT: false,
            WORKPLACE_INSTRUCTION_DELETE: false,
        });

        render(<WorkplaceInstructionList workplaceId="1" />);

        expect(useGetWorkplaceInstructionsQueryMock).toHaveBeenCalledWith('1', false);
    });

    it('renders a link-type row with an "Open link" anchor', () => {
        render(<WorkplaceInstructionList workplaceId="1" />);

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://example.com');
        expect(link).toHaveTextContent('Open enum|link');
    });

    it('renders an image-type row with a static url and "View image" text', () => {
        useGetWorkplaceInstructionsQueryMock.mockReturnValue({
            data: { data: [makeInstruction({ id: 2, type: 'image', content: 'path/to/img.jpg' })] },
            isLoading: false,
        });

        render(<WorkplaceInstructionList workplaceId="1" />);

        const link = screen.getByRole('link');
        expect(link).toHaveTextContent('View image');
        expect(link.getAttribute('href')).toContain('path/to/img.jpg');
    });

    it('disables the Add button when WORKPLACE_INSTRUCTION_CREATE is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_INSTRUCTION_LIST: true,
            WORKPLACE_INSTRUCTION_CREATE: false,
            WORKPLACE_INSTRUCTION_EDIT: true,
            WORKPLACE_INSTRUCTION_DELETE: true,
        });

        render(<WorkplaceInstructionList workplaceId="1" />);

        expect(screen.getByTestId('add-btn')).toBeDisabled();
    });

    it('opens the create modal with a null instruction when Add is clicked', () => {
        render(<WorkplaceInstructionList workplaceId="1" />);

        fireEvent.click(screen.getByTestId('add-btn'));

        expect(modalOpen).toHaveBeenCalled();
        expect(createUpdateSpy).toHaveBeenCalledWith(expect.objectContaining({ instruction: null }));
    });

    it('opens the edit modal with the row instruction when the edit icon is clicked', () => {
        const instruction = makeInstruction({ id: 9 });
        useGetWorkplaceInstructionsQueryMock.mockReturnValue({ data: { data: [instruction] }, isLoading: false });

        render(<WorkplaceInstructionList workplaceId="1" />);

        const [editIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(editIcon);

        expect(modalOpen).toHaveBeenCalled();
        expect(createUpdateSpy).toHaveBeenCalledWith(expect.objectContaining({ instruction }));
    });

    it('disables the edit and delete icons without permission', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_INSTRUCTION_LIST: true,
            WORKPLACE_INSTRUCTION_CREATE: true,
            WORKPLACE_INSTRUCTION_EDIT: false,
            WORKPLACE_INSTRUCTION_DELETE: false,
        });

        render(<WorkplaceInstructionList workplaceId="1" />);

        const icons = screen.getAllByTestId('action-icon');
        expect(icons[0]).toBeDisabled();
        expect(icons[1]).toBeDisabled();
    });

    it('opens the confirm modal and deletes the instruction on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteWorkplaceInstructionMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkplaceInstructionsQueryMock.mockReturnValue({
            data: { data: [makeInstruction({ id: 4 })] },
            isLoading: false,
        });

        render(<WorkplaceInstructionList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('4');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when delete fails', async () => {
        useDeleteWorkplaceInstructionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
            isPending: false,
        });

        render(<WorkplaceInstructionList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not delete instruction' }),
            );
        });
    });

    it('closes the confirm modal on cancel', () => {
        render(<WorkplaceInstructionList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-btn'));
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('passes isFetching to DDataTable while loading', () => {
        useGetWorkplaceInstructionsQueryMock.mockReturnValue({ data: undefined, isLoading: true });

        render(<WorkplaceInstructionList workplaceId="1" />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ isFetching: true, data: [] }));
    });
});
