import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AbsenceWarning from '@/pages/manage/shift/partials/absence_warning';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Box: ({ children }: any) => React.createElement('div', null, children),
    Tooltip: ({ children, label, zIndex }: any) =>
        React.createElement('div', { 'title': label, 'data-z-index': zIndex }, children),
}));

describe('AbsenceWarning', () => {
    it('renders nothing when absenceCount is 0', () => {
        const { container } = render(<AbsenceWarning absenceCount={0} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when absenceCount is negative', () => {
        const { container } = render(<AbsenceWarning absenceCount={-1} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders a tooltip with the absence count when absenceCount is at least 1', () => {
        render(<AbsenceWarning absenceCount={3} />);

        expect(screen.getByTitle('3 recent absences')).toBeInTheDocument();
    });

    it('forwards the zIndex prop to the tooltip', () => {
        render(<AbsenceWarning absenceCount={1} zIndex={500} />);

        expect(screen.getByTitle('1 recent absences')).toHaveAttribute('data-z-index', '500');
    });
});
