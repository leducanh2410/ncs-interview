import React from 'react';
import { createEvent, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { UserPaperItem } from '@/types/models/user_paper';
import PaperFolders from './paper_folders';

const { moveMutateAsyncMock, restoreMutateAsyncMock, notificationsShowMock } = vi.hoisted(() => ({
    moveMutateAsyncMock: vi.fn(),
    restoreMutateAsyncMock: vi.fn(),
    notificationsShowMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: any) => {
            if (opts?.count != null) return key.replace('{{count}}', String(opts.count));
            return key;
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Checkbox: ({ checked, disabled, onChange, onClick, indeterminate }: any) =>
        React.createElement('input', { type: 'checkbox', checked: checked || false, disabled, onChange, onClick }),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Menu: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        Target: ({ children }: any) => React.createElement('div', null, children),
        Dropdown: ({ children }: any) => React.createElement('div', null, children),
        Item: ({ children, onClick, disabled }: any) =>
            React.createElement('button', { type: 'button', onClick, disabled }, children),
    }),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h3', null, children),
    UnstyledButton: ({ children, onClick }: any) =>
        React.createElement('button', { type: 'button', onClick }, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel, isProcessing }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement(
                      'button',
                      { type: 'button', onClick: onConfirm, disabled: isProcessing },
                      'Confirm',
                  ),
                  React.createElement('button', { type: 'button', onClick: onCancel }, 'Cancel'),
              )
            : null,
}));

vi.mock('./paper_file_row', () => ({
    default: ({ item, onContextMenu, onRestore, onToggleSelect, isSelected }: any) =>
        React.createElement(
            'div',
            {
                'data-testid': `file-row-${item.id}`,
                'data-selected': isSelected,
                'onContextMenu': (event: any) => onContextMenu(event, item),
            },
            React.createElement('span', null, item.file_name),
            React.createElement('button', { type: 'button', onClick: () => onRestore(item.id) }, `restore-${item.id}`),
            React.createElement('button', { type: 'button', onClick: () => onToggleSelect(item) }, `select-${item.id}`),
        ),
}));

vi.mock('./paper_preview_modal', () => ({
    default: () => null,
    getFileCategory: (name: string) => {
        if (/\.(jpg|jpeg|png|gif|webp)$/.test(name)) return 'image';
        if (name.endsWith('.pdf')) return 'pdf';
        return 'other';
    },
}));

vi.mock('@/pages/admin/user/api', () => ({
    useMoveUserPaperMutation: () => ({ mutateAsync: moveMutateAsyncMock }),
    useRestoreUserPaperMutation: () => ({ mutateAsync: restoreMutateAsyncMock }),
}));

vi.mock('react-icons/lu', () => ({
    LuChevronDown: () => null,
    LuFolder: () => null,
    LuFolderCheck: () => null,
    LuFolderInput: () => null,
    LuFolderOpen: () => null,
    LuTrash2: () => React.createElement('span', null, '🗑'),
    LuUndo2: () => React.createElement('span', null, '↩'),
}));

const makeItem = (id: number, folder: UserPaperItem['folder']) =>
    ({
        id,
        user_id: 1,
        type: 'passport',
        file_name: `file${id}.pdf`,
        file_path: `uploads/file${id}.pdf`,
        folder,
        approved_at: null,
        rejected_at: null,
        created_at: '2024-01-01T00:00:00Z',
    }) as unknown as UserPaperItem;

describe('PaperFolders', () => {
    beforeEach(() => vi.clearAllMocks());

    it('renders four folder sections', () => {
        render(<PaperFolders title="Processed papers" items={[]} userId="42" />);

        expect(screen.getByText('Current papers')).toBeInTheDocument();
        expect(screen.getByText('Old papers')).toBeInTheDocument();
        expect(screen.getByText('Rejected papers')).toBeInTheDocument();
        expect(screen.getByText('Deleted papers')).toBeInTheDocument();
    });

    it('renders the title', () => {
        render(<PaperFolders title="Processed papers" items={[]} userId="42" />);

        expect(screen.getByText('Processed papers')).toBeInTheDocument();
    });

    it('shows empty message in the current folder when it has no files', () => {
        render(<PaperFolders title="Processed papers" items={[]} userId="42" />);

        expect(screen.getByText('No papers in this folder')).toBeInTheDocument();
    });

    it('renders file rows for items in the current folder', () => {
        const items = [makeItem(1, 'current'), makeItem(2, 'current')];

        render(<PaperFolders title="Processed papers" items={items} userId="42" />);

        expect(screen.getByTestId('file-row-1')).toBeInTheDocument();
        expect(screen.getByTestId('file-row-2')).toBeInTheDocument();
    });

    it('offers no bulk delete action once files are selected', async () => {
        const user = userEvent.setup();
        const items = [makeItem(5, 'current')];

        render(<PaperFolders title="Processed papers" items={items} userId="42" />);

        await user.click(screen.getByRole('button', { name: 'select-5' }));

        expect(screen.getByText('1 selected paper')).toBeInTheDocument();
        expect(screen.queryByText('🗑')).not.toBeInTheDocument();
    });

    it('disables selection in the rejected folder, which has no action left', () => {
        render(<PaperFolders title="Processed papers" items={[makeItem(9, 'rejected')]} userId="42" />);

        // One header checkbox per folder, in render order: current, old, rejected, deleted
        const [current, , rejected] = screen.getAllByRole('checkbox');
        expect(current).not.toBeDisabled();
        expect(rejected).toBeDisabled();
    });

    it('opens its own context menu on a movable row, suppressing the browser one', () => {
        render(<PaperFolders title="Processed papers" items={[makeItem(3, 'current')]} userId="42" />);
        const row = screen.getByTestId('file-row-3');

        const event = createEvent.contextMenu(row);
        fireEvent(row, event);

        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByTestId('file-row-3')).toHaveAttribute('data-selected', 'true');
    });

    it('leaves the browser context menu alone in the rejected folder', async () => {
        const user = userEvent.setup();

        render(<PaperFolders title="Processed papers" items={[makeItem(9, 'rejected')]} userId="42" />);

        await user.click(screen.getByText('Rejected papers'));
        const row = screen.getByTestId('file-row-9');
        const event = createEvent.contextMenu(row);
        fireEvent(row, event);

        expect(event.defaultPrevented).toBe(false);
        expect(screen.getByTestId('file-row-9')).toHaveAttribute('data-selected', 'false');
    });

    it('clicking restore on a deleted row opens restore confirm modal', async () => {
        const user = userEvent.setup();
        const items = [makeItem(7, 'deleted')];

        render(<PaperFolders title="Processed papers" items={items} userId="42" />);

        // Expand the deleted folder first (it's collapsed by default)
        await user.click(screen.getByText('Deleted papers'));
        await user.click(screen.getByRole('button', { name: 'restore-7' }));

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
    });

    it('confirming restore calls restoreMutation and shows success', async () => {
        const user = userEvent.setup();
        restoreMutateAsyncMock.mockResolvedValue({});
        const items = [makeItem(7, 'deleted')];

        render(<PaperFolders title="Processed papers" items={items} userId="42" />);

        await user.click(screen.getByText('Deleted papers'));
        await user.click(screen.getByRole('button', { name: 'restore-7' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(restoreMutateAsyncMock).toHaveBeenCalledWith([7]);
        });
        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({ color: 'teal', message: 'Successfully restored user paper' }),
        );
    });
});
