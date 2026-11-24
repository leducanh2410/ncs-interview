import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { UserPaperItem } from '@/types/models/user_paper';
import PaperPreviewModal, { getFileCategory } from './paper_preview_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal', 'data-title': title }, children) : null,
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { getText: (_: any, type: string) => type },
}));

vi.mock('@/helpers/url.helper', () => ({
    default: { getStaticUrl: (path: string) => `/static/${path}` },
}));

vi.mock('@/enums/user_paper_type.enum', () => ({ UserPaperTypeEnum: {} }));

const makeItem = (fileName: string, overrides: Partial<UserPaperItem> = {}): UserPaperItem =>
    ({
        id: 1,
        user_id: 1,
        type: 'passport',
        file_name: fileName,
        file_path: `uploads/${fileName}`,
        folder: 'current',
        approved_at: null,
        rejected_at: null,
        created_at: '2024-01-01T00:00:00Z',
        ...overrides,
    }) as UserPaperItem;

describe('getFileCategory', () => {
    it.each([['photo.jpg'], ['photo.jpeg'], ['photo.png'], ['photo.gif'], ['photo.webp']])(
        'returns "image" for %s',
        (fileName) => {
            expect(getFileCategory(fileName)).toBe('image');
        },
    );

    it('returns "pdf" for .pdf files', () => {
        expect(getFileCategory('document.pdf')).toBe('pdf');
    });

    it.each([['file.docx'], ['file.xlsx'], ['file.zip']])('returns "other" for %s', (fileName) => {
        expect(getFileCategory(fileName)).toBe('other');
    });
});

describe('PaperPreviewModal', () => {
    it('returns null when item is null', () => {
        const { container } = render(<PaperPreviewModal opened={true} onClose={() => {}} item={null} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders img element for image files', () => {
        render(<PaperPreviewModal opened={true} onClose={() => {}} item={makeItem('portrait.jpg')} />);

        expect(screen.getByRole('img')).toBeInTheDocument();
        expect(screen.queryByTitle(/portrait\.jpg/)).not.toBeInTheDocument(); // no iframe
    });

    it('renders iframe for PDF files', () => {
        render(<PaperPreviewModal opened={true} onClose={() => {}} item={makeItem('contract.pdf')} />);

        expect(document.querySelector('iframe')).toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('renders a download link for other file types', () => {
        render(<PaperPreviewModal opened={true} onClose={() => {}} item={makeItem('spreadsheet.xlsx')} />);

        expect(screen.getByText(/Download file/)).toBeInTheDocument();
        expect(screen.getByRole('link')).toBeInTheDocument();
        expect(document.querySelector('iframe')).not.toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('renders nothing when modal is closed', () => {
        render(<PaperPreviewModal opened={false} onClose={() => {}} item={makeItem('photo.jpg')} />);

        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });
});
