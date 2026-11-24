import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { UserPaperItem } from '@/types/models/user_paper';
import PaperFileRow from './paper_file_row';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, color }: any) =>
        React.createElement('button', { 'type': 'button', onClick, disabled, 'data-color': color }, children),
    Anchor: ({ children, href, component, type, onClick }: any) =>
        component === 'button'
            ? React.createElement('button', { type, onClick }, children)
            : React.createElement('a', { href }, children),
    Checkbox: ({ checked, disabled, onChange, onClick }: any) =>
        React.createElement('input', { type: 'checkbox', checked, disabled, onChange, onClick }),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (d: string) => d ?? '' },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { getText: (_: any, type: string) => type },
}));

vi.mock('@/helpers/url.helper', () => ({
    default: { getStaticUrl: (path: string) => `/static/${path}` },
}));

vi.mock('@/enums/user_paper_type.enum', () => ({ UserPaperTypeEnum: {} }));

vi.mock('react-icons/lu', () => ({
    LuFile: () => React.createElement('span', { 'data-testid': 'icon-file' }),
    LuFileText: () => React.createElement('span', { 'data-testid': 'icon-pdf' }),
    LuImage: () => React.createElement('span', { 'data-testid': 'icon-image' }),
    LuTrash2: () => React.createElement('span', null, '🗑'),
    LuUndo2: () => React.createElement('span', null, '↩'),
}));

const makeItem = (overrides: Partial<UserPaperItem> = {}): UserPaperItem =>
    ({
        id: 1,
        user_id: 1,
        type: 'passport',
        file_name: 'document.pdf',
        file_path: 'uploads/document.pdf',
        folder: 'current',
        approved_at: null,
        rejected_at: null,
        created_at: '2024-01-01T00:00:00Z',
        ...overrides,
    }) as UserPaperItem;

const defaultProps = {
    isSelected: false,
    isSelectionDisabled: false,
    onContextMenu: vi.fn(),
    onPreview: vi.fn(),
    onRestore: vi.fn(),
    onToggleSelect: vi.fn(),
};

describe('PaperFileRow', () => {
    beforeEach(() => vi.clearAllMocks());

    it('renders the file name', () => {
        render(<PaperFileRow item={makeItem()} {...defaultProps} />);

        expect(screen.getByText('document.pdf')).toBeInTheDocument();
    });

    it('renders image icon for image files', () => {
        render(<PaperFileRow item={makeItem({ file_name: 'photo.jpg' })} {...defaultProps} />);

        expect(screen.getByTestId('icon-image')).toBeInTheDocument();
    });

    it('renders pdf icon for pdf files', () => {
        render(<PaperFileRow item={makeItem()} {...defaultProps} />);

        expect(screen.getByTestId('icon-pdf')).toBeInTheDocument();
    });

    it('renders generic file icon for other file types', () => {
        render(<PaperFileRow item={makeItem({ file_name: 'archive.zip' })} {...defaultProps} />);

        expect(screen.getByTestId('icon-file')).toBeInTheDocument();
    });

    it('shows Preview button for image files', () => {
        render(<PaperFileRow item={makeItem({ file_name: 'photo.png' })} {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Preview' })).toBeInTheDocument();
    });

    it('shows Preview button for PDF files', () => {
        render(<PaperFileRow item={makeItem()} {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Preview' })).toBeInTheDocument();
    });

    it('shows Download link for other file types', () => {
        render(<PaperFileRow item={makeItem({ file_name: 'data.xlsx' })} {...defaultProps} />);

        expect(screen.getByRole('link', { name: 'Download' })).toBeInTheDocument();
    });

    it('clicking Preview calls onPreview with the item', async () => {
        const user = userEvent.setup();
        const item = makeItem({ file_name: 'photo.jpg' });
        const onPreview = vi.fn();

        render(<PaperFileRow item={item} {...defaultProps} onPreview={onPreview} />);

        await user.click(screen.getByRole('button', { name: 'Preview' }));

        expect(onPreview).toHaveBeenCalledWith(item);
    });

    it('never shows a delete button, whatever folder the file is in', () => {
        for (const folder of ['current', 'old', 'rejected', 'deleted'] as const) {
            const { unmount } = render(<PaperFileRow item={makeItem({ folder })} {...defaultProps} />);

            expect(screen.queryByRole('button', { name: '🗑' })).not.toBeInTheDocument();
            unmount();
        }
    });

    it('shows restore button for deleted files', () => {
        render(<PaperFileRow item={makeItem({ folder: 'deleted' })} {...defaultProps} />);

        expect(screen.getByRole('button', { name: '↩' })).toBeInTheDocument();
    });

    it('clicking restore calls onRestore with item id', async () => {
        const user = userEvent.setup();
        const onRestore = vi.fn();

        render(<PaperFileRow item={makeItem({ id: 7, folder: 'deleted' })} {...defaultProps} onRestore={onRestore} />);

        await user.click(screen.getByRole('button', { name: '↩' }));

        expect(onRestore).toHaveBeenCalledWith(7);
    });

    it('shows no action button for non-deleted files', () => {
        render(<PaperFileRow item={makeItem({ folder: 'current' })} {...defaultProps} />);

        expect(screen.queryByRole('button', { name: '↩' })).not.toBeInTheDocument();
    });

    it('checkbox is selected when isSelected=true', () => {
        render(<PaperFileRow item={makeItem()} {...defaultProps} isSelected={true} />);

        expect(screen.getByRole('checkbox')).toBeChecked();
    });

    it('clicking checkbox calls onToggleSelect with item', async () => {
        const user = userEvent.setup();
        const item = makeItem();
        const onToggleSelect = vi.fn();

        render(<PaperFileRow item={item} {...defaultProps} onToggleSelect={onToggleSelect} />);

        await user.click(screen.getByRole('checkbox'));

        expect(onToggleSelect).toHaveBeenCalledWith(item);
    });
});
