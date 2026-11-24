import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { UserPaperItem } from '@/types/models/user_paper';
import PaperApprovalFolders from './paper_approval_folders';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, string>) =>
            opts?.date != null ? key.replace('{{date}}', opts.date) : key,
    }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    UnstyledButton: ({ children, onClick }: any) =>
        React.createElement('button', { type: 'button', onClick }, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string) => value },
}));

vi.mock('./paper_file_row', () => ({
    default: ({ item, onPreview, isSelectionDisabled }: any) =>
        React.createElement(
            'div',
            { 'data-testid': `file-row-${item.id}`, 'data-selection-disabled': isSelectionDisabled },
            React.createElement('button', { type: 'button', onClick: () => onPreview(item) }, `preview-${item.id}`),
        ),
}));

vi.mock('react-icons/lu', () => ({
    LuChevronDown: () => null,
    LuFolderCheck: () => null,
}));

const makeItem = (id: number, type: string, approvedAt: string) =>
    ({
        id,
        user_paper_id: 1,
        type,
        file_name: `file${id}.jpg`,
        file_path: `uploads/file${id}.jpg`,
        folder: 'current',
        approved_at: approvedAt,
        rejected_at: null,
        deleted_at: null,
        created_at: '2026-01-01T00:00:00Z',
    }) as unknown as UserPaperItem;

const items = [
    makeItem(1, 'foreigner_card_front', '2026-03-01 10:00:00'),
    makeItem(2, 'portrait_image', '2026-03-01 10:00:00'),
    makeItem(3, 'foreigner_card_front', '2026-09-01 09:00:00'),
];

describe('PaperApprovalFolders', () => {
    it('renders one folder per approval, newest first, with the latest one marked', () => {
        render(<PaperApprovalFolders items={items} confirmedApprovalAt={null} onPreview={vi.fn()} />);

        const labels = screen.getAllByText(/^Approved on /).map((element) => element.textContent);
        expect(labels).toEqual(['Approved on 2026-09-01 09:00:00', 'Approved on 2026-03-01 10:00:00']);
        expect(screen.getAllByText('Latest approval')).toHaveLength(1);
    });

    it('shows the approval documents read-only when the folder is expanded', async () => {
        const onPreview = vi.fn();
        render(<PaperApprovalFolders items={items} confirmedApprovalAt={null} onPreview={onPreview} />);

        expect(screen.queryByTestId('file-row-3')).not.toBeInTheDocument();

        await userEvent.click(screen.getByText('Approved on 2026-09-01 09:00:00'));

        expect(screen.getByTestId('file-row-3')).toHaveAttribute('data-selection-disabled', 'true');
        expect(screen.getByTestId('file-row-2')).toBeInTheDocument();
        expect(screen.queryByTestId('file-row-1')).not.toBeInTheDocument();

        await userEvent.click(screen.getByText('preview-3'));
        expect(onPreview).toHaveBeenCalledWith(items[2]);
    });

    it('renders nothing when no approval exists', () => {
        const { container } = render(
            <PaperApprovalFolders items={[]} confirmedApprovalAt={null} onPreview={vi.fn()} />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
