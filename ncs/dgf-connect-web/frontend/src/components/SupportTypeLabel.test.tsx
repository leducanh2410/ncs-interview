import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SupportType } from '@/types/models/chat';
import SupportTypeLabel from './SupportTypeLabel';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children, color, variant }: { children: React.ReactNode; color: string; variant: string }) =>
        React.createElement('span', { 'data-color': color, 'data-variant': variant }, children),
}));

const TYPES: { type: SupportType; color: string; key: string }[] = [
    { type: 'document', color: 'blue', key: 'support_type|document' },
    { type: 'shift', color: 'green', key: 'support_type|shift' },
    { type: 'salary', color: 'orange', key: 'support_type|salary' },
    { type: 'transportation', color: 'violet', key: 'support_type|transportation' },
    { type: 'other', color: 'gray', key: 'support_type|other' },
];

describe('SupportTypeLabel', () => {
    it.each(TYPES)('renders badge with correct color for type "$type"', ({ type, color, key }) => {
        render(<SupportTypeLabel type={type} />);
        const badge = screen.getByText(key);
        expect(badge.getAttribute('data-color')).toBe(color);
        expect(badge.getAttribute('data-variant')).toBe('light');
    });

    it.each(TYPES)('renders translation key for type "$type"', ({ type, key }) => {
        render(<SupportTypeLabel type={type} />);
        expect(screen.getByText(key)).toBeTruthy();
    });

    it('renders nothing when type is null', () => {
        const { container } = render(<SupportTypeLabel type={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when type is undefined', () => {
        const { container } = render(<SupportTypeLabel type={undefined} />);
        expect(container.firstChild).toBeNull();
    });
});
