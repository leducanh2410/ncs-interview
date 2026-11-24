import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import VerdictBadge from '@/pages/admin/user-paper-reconciliation/partials/verdict_badge';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
    }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children, color }: any) => React.createElement('span', { 'data-color': color }, children),
}));

describe('VerdictBadge', () => {
    it.each([
        ['matched', 'green'],
        ['mismatched', 'red'],
        ['employee_data_missing', 'gray'],
        ['not_comparable', 'gray'],
        ['not_uploaded', 'yellow'],
    ])('gives %s its own colour', (verdict, color) => {
        render(<VerdictBadge verdict={verdict} />);

        expect(screen.getByText(/./).getAttribute('data-color')).toBe(color);
    });

    it('names how many fields differ on a mismatch', () => {
        render(<VerdictBadge verdict="mismatched" mismatchCount={2} />);

        expect(screen.getByText(/\{"count":2\}/)).toBeInTheDocument();
    });

    it('falls back to a neutral badge for a verdict this build does not know', () => {
        render(<VerdictBadge verdict="something_new" />);

        expect(screen.getByText('something_new').getAttribute('data-color')).toBe('gray');
    });
});
